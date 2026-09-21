<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Log\Logger;
use App\Core\Notifications\WebPush\WebPush;
use App\Core\Security\RateLimiter;
use App\Core\Settings\Settings;
use Throwable;

/**
 * Upozornění na telefon — jediné místo, které posílá web push.
 *
 * Obdoba `SupportService::notifyByMail()`, jen místo e-mailu pípne telefon.
 * Klientská aplikace k tomu má `PushDispatcher` nad tabulkou `notifications`
 * a frontu, kterou vyprazdňuje cron jádra; správa žádnou tabulku oznámení
 * ani vlastní cron nemá (jediný cron běží jednou denně v 6:00), takže by
 * fronta znamenala „pípni mi ráno". **Posílá se proto rovnou v místě
 * události**, synchronně — účty jsou dva, jsou to dva až čtyři požadavky.
 *
 * ## Nic odsud nesmí vyletět
 *
 * Push k podpoře vzniká uvnitř požadavku, který posílá **server instance**
 * (`POST /system/support/push`), a chybové upozornění dokonce uvnitř `catch`
 * bloku `Kernel::handle()`. Výjimka by v prvním případě vrátila instanci 500
 * kvůli notifikaci, ve druhém by shodila i chybovou stránku. `send()` proto
 * chytá úplně všechno a nanejvýš zapíše řádek do logu.
 *
 * ## Rozpočet času
 *
 * Volající na odpověď čeká, takže celý průchod má strop `MAX_SECONDS`; před
 * každým dalším zařízením se čas kontroluje a zbytek se přeskočí. Jednotlivý
 * požadavek hlídá `WebPush` (3 s na spojení, 5 s celkem). Kdyby to jednou
 * vadilo, správným řešením je odeslat až po odpovědi
 * (`fastcgi_finish_request()`) — tohle je to místo, kam by to patřilo.
 */
final class PushNotifier
{
    /** Kolik zařízení obslouží jeden průchod — pojistka, ne skutečný limit. */
    public const MAX_DEVICES = 20;

    /** Výchozí strop na celý průchod, včetně všech zařízení. */
    public const MAX_SECONDS = 10;

    private const BUCKET = 'push';

    /**
     * Nejvýš tolik upozornění za hodinu na skupinu — brzda proti salvě.
     *
     * Chyby mají nejnižší strop schválně: rozbitá stránka, do které buší
     * robot, vyrobí novou značku každou minutu a telefon by se nezastavil.
     * Tři zprávy stačí k tomu, aby si člověk sedl k logu.
     */
    private const MAX_PER_HOUR = [
        'sites' => 12,
        'ssl' => 6,
        'updates' => 3,
        'reports' => 6,
        'error' => 3,
        'ops' => 6,
    ];

    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly Settings $settings,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
        private readonly ?WebPush $webPush,
        private readonly string $urlPrefix,
        // Vteřin na celý průchod. Parametr jen kvůli testu — v provozu se
        // nikde nenastavuje a platí `MAX_SECONDS`.
        private readonly float $maxSeconds = self::MAX_SECONDS,
    ) {
    }

    /** Jsou klíče připravené? Bez nich se push vůbec nenabízí. */
    public function ready(): bool
    {
        return $this->webPush !== null;
    }

    /**
     * Pošle upozornění na všechna přihlášená zařízení.
     *
     * @param string $event       skupina z Nastavení → Oznámení: sites | ssl | updates | reports | error | ops
     * @param string $path        kam má klepnutí vést, v rámci správy („podpora/1042")
     * @param string $onceKey     co se nemá opakovat (číslo žádosti, značka chyby); prázdné = neomezovat
     * @param int    $onceMinutes jak dlouho se totéž nemá opakovat
     *
     * @return int počet zařízení, kterým zpráva odešla
     */
    public function send(
        string $event,
        string $title,
        string $body,
        string $path,
        string $onceKey = '',
        int $onceMinutes = 60,
    ): int {
        try {
            return $this->dispatch($event, $title, $body, $path, $onceKey, $onceMinutes);
        } catch (Throwable $e) {
            // Poslední záchrana — sem se dostane jen to, co neodchytily
            // vnitřní pojistky, typicky nedostupná databáze.
            $this->quietly(fn () => $this->logger->warning('Upozornění na telefon selhalo', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]));

            return 0;
        }
    }

    private function dispatch(string $event, string $title, string $body, string $path, string $onceKey, int $onceMinutes): int
    {
        if ($this->webPush === null || !$this->enabled($event)) {
            return 0;
        }

        if ($this->muted($event, $onceKey, $onceMinutes)) {
            return 0;
        }

        $this->remember($event, $onceKey);

        return $this->deliver($event, $title, $body, $path)['sent'];
    }

    /**
     * Zkušební upozornění — jde **mimo skupiny i mimo tlumení**: člověk si ho
     * vyžádal tlačítkem a musí přijít, jinak se zapojení nedá ověřit.
     *
     * Na rozdíl od `send()` vrací i **důvod neúspěchu**. Zkouška, která umí jen
     * „neodešlo nic", je k ničemu: příčiny jsou tři úplně jiné (žádné zařízení ·
     * nepoužitelný klíč · push služba odmítla) a každá se řeší jinde. Bez toho
     * zbývá provozovateli jen log na serveru.
     *
     * @return array{sent: int, devices: int, error: string}
     */
    public function test(): array
    {
        if ($this->webPush === null) {
            return ['sent' => 0, 'devices' => 0, 'error' => 'Správa nemá použitelný podpisový klíč.'];
        }

        try {
            return $this->deliver(
                'test',
                'Zkušební upozornění',
                'Když tohle vidíte na telefonu, upozornění ze správy fungují.',
                '/',
            );
        } catch (Throwable $e) {
            return ['sent' => 0, 'devices' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vlastní rozeslání — bez ptaní, jestli se má.
     *
     * Vrací i počet oslovených zařízení a poslední chybu, aby uměla zkouška
     * říct, co přesně se nepovedlo (viz `test()`).
     *
     * @return array{sent: int, devices: int, error: string}
     */
    private function deliver(string $event, string $title, string $body, string $path): array
    {
        $devices = $this->subscriptions->forActiveUsers(self::MAX_DEVICES);

        if ($devices === []) {
            return ['sent' => 0, 'devices' => 0, 'error' => ''];
        }

        $payload = [
            // Značka notifikace v telefonu: novější zpráva k téže věci starou
            // nahradí, místo aby se lišta plnila duplicitami.
            'id' => substr(md5($event . '|' . $path), 0, 12),
            'title' => $title,
            'body' => mb_substr(trim($body), 0, 200),
            'url' => $this->urlPrefix . ltrim($path, '/'),
        ];

        $started = microtime(true);
        $delivered = 0;
        $handled = 0;
        $error = '';

        foreach ($devices as $device) {
            if (microtime(true) - $started > $this->maxSeconds) {
                $error = 'Odesílání se nevešlo do rozpočtu ' . $this->maxSeconds . ' s — push služba neodpovídá.';
                $this->quietly(fn () => $this->logger->warning('Rozesílání upozornění se nevešlo do rozpočtu', [
                    'event' => $event,
                    'odeslano' => $delivered,
                    'zbyva' => count($devices) - $handled,
                ]));

                break;
            }

            $handled++;
            $failure = $this->sendOne($device, $payload);

            if ($failure === '') {
                $delivered++;
            } else {
                $error = $failure;
            }
        }

        return ['sent' => $delivered, 'devices' => count($devices), 'error' => $error];
    }

    /**
     * Jedno zařízení. Mrtvý odběr (404/410) mizí hned, opakovaná jiná chyba
     * ho smaže po `PushSubscriptions::MAX_FAILURES`.
     *
     * @param array{id: int, endpoint: string, p256dh: string, auth: string, failed_count: int} $device
     * @param array<string, mixed> $payload
     *
     * @return string prázdné = doručeno, jinak popis chyby pro zkušební upozornění
     */
    private function sendOne(array $device, array $payload): string
    {
        try {
            $response = $this->webPush?->send($device, $payload) ?? ['result' => WebPush::FAILED, 'error' => 'vypnuto'];
        } catch (Throwable $e) {
            $response = ['result' => WebPush::FAILED, 'error' => $e->getMessage()];
        }

        if ($response['result'] === WebPush::OK) {
            $this->quietly(fn () => $this->subscriptions->markDelivered($device['id']));

            return '';
        }

        if ($response['result'] === WebPush::GONE) {
            $this->quietly(fn () => $this->subscriptions->delete($device['id']));

            return 'Odběr už u push služby neplatí — zařízení se musí přihlásit znovu.';
        }

        $this->quietly(function () use ($device, $response): void {
            $removed = $this->subscriptions->markFailed($device['id'], $device['failed_count']);
            $this->logger->warning('Upozornění se nepodařilo doručit', [
                'zarizeni' => $device['id'],
                'error' => (string) ($response['error'] ?? ''),
                'odebrano' => $removed,
            ]);
        });

        return (string) ($response['error'] ?? '') !== ''
            ? (string) $response['error']
            : 'Push služba zprávu nepřijala.';
    }

    /** Skupina se dá vypnout v Nastavení → Oznámení; výchozí je zapnuto. */
    private function enabled(string $event): bool
    {
        return $this->settings->get('push_on_' . $event, '1') !== '0';
    }

    /**
     * Má se mlčet? Dvě otázky: „neposílali jsme tohle právě teď" (`onceKey`)
     * a „nepřekročili jsme hodinový strop skupiny".
     *
     * Selhání počítadla znamená **mlčet**. Je to opačně než u příjmu zpráv
     * podpory (`SupportPushController::tooMany()`), kde nedostupné počítadlo
     * zprávu radši propustí: tam by se ztratil klientův dotaz, tady jde jen
     * o upozornění — a nedostupné počítadlo obvykle znamená rozbitou
     * databázi, tedy přesně stav, kdy salvu nechceme.
     */
    private function muted(string $event, string $onceKey, int $onceMinutes): bool
    {
        try {
            if ($onceKey !== '' && $this->limiter->tooMany(self::BUCKET, $event . ':' . $onceKey, 1, $onceMinutes)) {
                return true;
            }

            return $this->limiter->tooMany(self::BUCKET, $event, self::MAX_PER_HOUR[$event] ?? 6, 60);
        } catch (Throwable) {
            return true;
        }
    }

    private function remember(string $event, string $onceKey): void
    {
        $this->quietly(function () use ($event, $onceKey): void {
            $this->limiter->record(self::BUCKET, $event);

            if ($onceKey !== '') {
                $this->limiter->record(self::BUCKET, $event . ':' . $onceKey);
            }
        });
    }

    /**
     * Provede doprovodný krok a spolkne jeho chybu.
     *
     * Zápisy do databáze a do logu jsou tu vedlejší — když selžou, zpráva už
     * stejně odešla a shodit kvůli tomu volajícího by bylo horší.
     */
    private function quietly(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // Schválně mlčky: viz docblock třídy.
        }
    }
}
