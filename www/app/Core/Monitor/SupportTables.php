<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Settings\Settings;

/**
 * Konce podpory PHP, MySQL a MariaDB ze služby endoflife.date.
 *
 * Tabulky v `PhpSupport::TABLE` a `DbSupport::TABLE` se píšou ručně
 * a stárnou. Tady se jednou za měsíc (krok cronu) nebo tlačítkem
 * v Nastavení → Monitoring stáhnou aktuální data, uloží do `settings`
 * a obě třídy je pak používají místo vestavěných tabulek. Když se
 * stažení nepovede, platí dál poslední uložená data, případně vestavěné
 * tabulky — správa nikdy nezůstane bez údajů.
 */
final class SupportTables
{
    /** produkt na endoflife.date => typ, jak ho hlásí plugin ('' = PHP) */
    public const PRODUCTS = ['php' => '', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'];

    public const SOURCE = 'https://endoflife.date/api/%s.json';

    /** Po kolika dnech má cron data ověřit znovu. */
    public const REFRESH_DAYS = 30;

    private const SETTING = 'support_tables';

    /** Konec podpory ještě neoznámený — verze se bere jako podporovaná. */
    private const NO_END = '9999-12-31';

    /** Podpora skončila, ale služba neuvádí kdy. */
    private const ENDED = '2000-01-01';

    /** @var (callable(string): ?string)|null stahování — v testech podvržené */
    private $fetcher;

    public function __construct(
        private readonly Settings $settings,
        ?callable $fetcher = null,
        private readonly int $timeout = 10,
    ) {
        $this->fetcher = $fetcher;
    }

    /**
     * Uložená data: kdy se ověřovalo, s jakým výsledkem, tabulky a co se
     * při posledním ověření změnilo.
     *
     * @return array{checked_at: string, error: ?string, php: array<string, string>, db: array<string, array<string, string>>, changes: array<int, string>}|null
     */
    public function stored(): ?array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);

        return is_array($data) && isset($data['checked_at'], $data['php'], $data['db']) ? $data + ['error' => null, 'changes' => []] : null;
    }

    /** Uložená data předat `PhpSupport` a `DbSupport` (volá Kernel na začátku požadavku). */
    public function apply(): void
    {
        $stored = $this->stored();
        PhpSupport::useTable($stored !== null && $stored['php'] !== [] ? $stored['php'] : null);
        DbSupport::useTables($stored !== null && $stored['db'] !== [] ? $stored['db'] : null);
    }

    public function isDue(?int $now = null): bool
    {
        $stored = $this->stored();

        return $stored === null || strtotime($stored['checked_at']) < ($now ?? time()) - self::REFRESH_DAYS * 86400;
    }

    /**
     * Stáhnout, porovnat s tím, co správa používá teď, uložit a použít.
     *
     * @return array{ok: bool, error: ?string, changes: array<int, string>}
     */
    public function refresh(?int $now = null): array
    {
        $php = [];
        $db = [];
        $failed = [];

        foreach (self::PRODUCTS as $product => $type) {
            $table = self::parse((string) ($this->fetch(sprintf(self::SOURCE, $product)) ?? ''));

            if ($table === []) {
                $failed[] = $product;
                continue;
            }

            if ($type === '') {
                $php = $table;
            } else {
                $db[$type] = $table;
            }
        }

        $previous = $this->stored();

        // Co se nestáhlo, zůstává z minula — jinak by výpadek jedné služby
        // vrátil daný produkt k vestavěné (starší) tabulce.
        $php = $php !== [] ? $php : ($previous['php'] ?? []);

        foreach (array_keys(DbSupport::TABLE) as $type) {
            $db[$type] ??= $previous['db'][$type] ?? [];
        }

        $db = array_filter($db);
        $changes = array_merge(
            self::diff('PHP', PhpSupport::table(), $php),
            ...array_map(static fn (string $type): array => self::diff($type, DbSupport::tables()[$type] ?? [], $db[$type] ?? []), array_keys($db)),
        );
        $error = $failed !== [] ? 'Nepodařilo se stáhnout: ' . implode(', ', $failed) . '.' : null;

        $this->settings->set(self::SETTING, (string) json_encode([
            'checked_at' => date('Y-m-d H:i:s', $now ?? time()),
            'error' => $error,
            'php' => $php,
            'db' => $db,
            'changes' => $changes,
        ], JSON_UNESCAPED_UNICODE));
        $this->apply();

        return ['ok' => $failed === [], 'error' => $error, 'changes' => $changes];
    }

    /**
     * Odpověď endoflife.date → minor verze => poslední den podpory.
     * Umí formát `/api/<produkt>.json` (seznam cyklů) i novější
     * `/api/v1/products/<produkt>` (`result.releases`).
     *
     * @return array<string, string>
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        $releases = is_array($data['result']['releases'] ?? null) ? $data['result']['releases'] : $data;

        if (!is_array($releases)) {
            return [];
        }

        $table = [];

        foreach ($releases as $release) {
            $cycle = (string) ($release['cycle'] ?? $release['name'] ?? '');
            $eol = $release['eol'] ?? $release['eolFrom'] ?? ($release['isEol'] ?? null);

            if (preg_match('/^\d+\.\d+$/', $cycle) !== 1) {
                continue;
            }

            $table[$cycle] = match (true) {
                is_string($eol) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eol) === 1 => $eol,
                $eol === true => self::ENDED,
                default => self::NO_END,
            };
        }

        uksort($table, 'version_compare');

        return $table;
    }

    /**
     * Rozdíly mezi tabulkou, kterou správa používá, a staženou — do přehledu
     * v nastavení („PHP 8.3: konec podpory 31. 12. 2027 → 31. 12. 2028“).
     *
     * @param array<string, string> $old
     * @param array<string, string> $new
     * @return array<int, string>
     */
    public static function diff(string $label, array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $minor => $end) {
            if (!isset($old[$minor])) {
                $changes[] = $label . ' ' . $minor . ': nově v tabulce, konec podpory ' . self::endLabel($end);
            } elseif ($old[$minor] !== $end) {
                $changes[] = $label . ' ' . $minor . ': konec podpory ' . self::endLabel($old[$minor]) . ' → ' . self::endLabel($end);
            }
        }

        return $changes;
    }

    public static function endLabel(string $end): string
    {
        return match ($end) {
            self::NO_END => 'zatím neoznámen',
            self::ENDED => 'už skončila',
            default => get_czech_date($end),
        };
    }

    private function fetch(string $url): ?string
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'MEDIAGRAFIK-Sprava-webu/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $status === 200 ? $body : null;
    }
}
