<?php

declare(strict_types=1);

/**
 * Rozesílání upozornění na telefon — `PushNotifier` a `VapidKeys`.
 *
 * Co by se v provozu pokazilo nejdřív:
 *  - **salva**: rozbitá stránka, do které buší robot, vyrobí novou značku
 *    každou minutu; bez stropu by telefon vibroval bez ustání,
 *  - **zdržení volajícího**: push k podpoře se posílá uvnitř požadavku od
 *    cronu, takže se musí vejít do rozpočtu,
 *  - **výjimka**: cokoli odsud vyletí, shodí volajícího — a u chybového
 *    upozornění dokonce i chybovou stránku.
 *
 * Push služba se nevolá, `WebPush` dostane podstrčený transport. Referenční
 * klíče odběru jsou z RFC 8291 (vlastní kopie, `WebPushTest` se načítá až po
 * tomhle souboru).
 */

use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Kernel;
use App\Core\Log\Logger;
use App\Core\Notifications\PushNotifier;
use App\Core\Notifications\PushSubscriptions;
use App\Core\Notifications\VapidKeys;
use App\Core\Notifications\WebPush\Vapid;
use App\Core\Notifications\WebPush\WebPush;
use App\Core\Security\RateLimiter;
use App\Core\Security\Secrets;
use App\Core\Settings\Settings;
use App\Core\View\Urls;

const PN_P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const PN_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
const PN_APP_KEY = '0f112233445566778899aabbccddeeff00112233445566778899aabbccddee0f';

/** Účet ve schématu správy. */
function pnUser(Connection $db, string $name): int
{
    $now = date('Y-m-d H:i:s');

    return $db->insert('users', [
        'username' => $name,
        'email' => $name . '@test.cz',
        'name' => $name,
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'is_active' => 1,
        'theme' => 'auto',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Rozesílač nad čerstvou databází s jedním účtem a zadanými zařízeními.
 *
 * Zásobník odeslaného je `ArrayObject` schválně: obyčejné pole by se při
 * návratu zkopírovalo a test by koukal na prázdný snímek.
 *
 * @param array<string, int> $devices endpoint => HTTP stav, který transport vrátí
 * @return array{0: PushNotifier, 1: Settings, 2: PushSubscriptions, 3: ArrayObject<int, array<string, mixed>>}
 */
function pnNotifier(array $devices, ?float $maxSeconds = null, ?callable $transport = null, bool $withKeys = true): array
{
    $db = freshTestDb();
    $secrets = new Secrets(PN_APP_KEY);
    $settings = new Settings($db, $secrets);
    $subs = new PushSubscriptions($db);
    $user = pnUser($db, 'spravce');

    foreach (array_keys($devices) as $endpoint) {
        $subs->save($user, $endpoint, PN_P256DH, PN_AUTH, 'ua');
    }

    $sent = new ArrayObject();
    $transport ??= static function (string $endpoint, array $headers, string $body) use ($sent, $devices): array {
        $sent[] = ['endpoint' => $endpoint, 'body' => $body];

        return ['status' => $devices[$endpoint] ?? 201, 'error' => ''];
    };

    $vapid = $withKeys ? Vapid::fromConfig(Vapid::generateKeys() + ['subject' => 'mailto:a@b.cz']) : null;

    $notifier = new PushNotifier(
        $subs,
        $settings,
        new RateLimiter($db),
        new Logger(sys_get_temp_dir() . '/sprava-push-' . getmypid()),
        $vapid !== null ? new WebPush($vapid, $transport(...)) : null,
        '/',
        $maxSeconds ?? PushNotifier::MAX_SECONDS,
    );

    return [$notifier, $settings, $subs, $sent];
}

/**
 * Kernel s přihlášeným účtem — pro cestu „prohlížeč hlásí odběr serveru".
 *
 * @return array{0: Kernel, 1: string} kernel a CSRF token
 */
function pnKernel(): array
{
    freshTestDb();

    $kernel = new Kernel([
        'environment' => 'development',
        'timezone' => 'Europe/Prague',
        'app_key' => PN_APP_KEY,
        'app_url' => 'https://sprava.test',
        'database' => [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'name' => getenv('DB_NAME') ?: 'sprava_webu_test',
            'user' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ],
        'storage_path' => sys_get_temp_dir() . '/sprava-push-kernel-' . getmypid(),
    ], WWW_ROOT);

    Urls::bind($kernel->url(...), $kernel->asset(...));

    $id = pnUser($kernel->db(), 'spravce');
    // Spárovaný telefon — bez něj Kernel pustí jen na párování (povinné 2FA).
    $kernel->users()->update($id, [
        'totp_secret' => $kernel->secrets()->encrypt(App\Core\Auth\Totp::generateSecret()),
        'totp_enabled_at' => date('Y-m-d H:i:s'),
    ]);
    $user = $kernel->users()->find($id);

    // Přihlášení nasucho — `Auth::login()` by chtělo heslo a rate limiter.
    $_SESSION = [
        'user_id' => $id,
        'auth_hash' => hash('sha256', $user['id'] . '|' . $user['password_hash']),
        'last_activity' => time(),
    ];

    $kernel->vapidKeys()->ensure();

    return [$kernel, $kernel->csrf()->token()];
}

/** Požadavek přesně v tom tvaru, jaký posílá fetch z push.js. */
function pnPost(Kernel $kernel, string $path, array $body, string $token): array
{
    $response = $kernel->handle(new Request(
        method: 'POST',
        path: $path,
        query: [],
        body: $body,
        files: [],
        cookies: [],
        headers: [
            'content-type' => 'application/json',
            'x-csrf-token' => $token,
            'x-requested-with' => 'XMLHttpRequest',
            'accept' => 'application/json',
            'user-agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537.36',
        ],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    ));

    return [$response->status(), (array) json_decode($response->body(), true)];
}

return [
    'vypnutá skupina neposílá nic' => function (): void {
        [$notifier, $settings, , $sent] = pnNotifier(['https://push.example.org/a' => 201]);

        $settings->set('push_on_sites', '0');
        assertSame(0, $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1'));
        assertSame(0, count($sent));

        // Výchozí stav je zapnuto — bez záznamu v nastavení se posílá.
        $settings->set('push_on_sites', '1');
        assertSame(1, $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1'));
    },

    'mrtvý odběr zmizí, živý dostane zprávu' => function (): void {
        [$notifier, , $subs, $sent] = pnNotifier([
            'https://push.example.org/zivy' => 201,
            'https://push.example.org/mrtvy' => 410,
        ]);

        assertSame(1, $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1'));
        assertSame(2, count($sent), 'zkusí se obě zařízení');
        assertSame(1, $subs->count(), 'mrtvý odběr se smaže hned, opakovat nemá smysl');
    },

    'stejná věc do okna pípne jen jednou' => function (): void {
        [$notifier, , , $sent] = pnNotifier(['https://push.example.org/a' => 201]);

        assertSame(1, $notifier->send('error', 'Chyba', 'x', '/', onceKey: 'err-2026-09-08-1012', onceMinutes: 5));
        assertSame(0, $notifier->send('error', 'Chyba', 'x', '/', onceKey: 'err-2026-09-08-1012', onceMinutes: 5));
        assertSame(1, $notifier->send('error', 'Chyba', 'x', '/', onceKey: 'err-2026-09-08-1013', onceMinutes: 5));
        assertSame(2, count($sent));
    },

    'hodinový strop skupiny zastaví salvu' => function (): void {
        [$notifier, , , $sent] = pnNotifier(['https://push.example.org/a' => 201]);

        // Rozbitá stránka vyrábí novou značku každou minutu — tlumení podle
        // klíče by ji nezastavilo, strop skupiny ano.
        for ($minute = 0; $minute < 8; $minute++) {
            $notifier->send('error', 'Chyba', 'x', '/', onceKey: 'err-' . $minute, onceMinutes: 5);
        }

        assertSame(3, count($sent), 'chyby mají strop tři za hodinu');
    },

    'rozesílání se vejde do rozpočtu, zbytek se přeskočí' => function (): void {
        $sent = [];
        $pomaly = static function (string $endpoint, array $headers, string $body) use (&$sent): array {
            $sent[] = $endpoint;
            usleep(120_000);

            return ['status' => 201, 'error' => ''];
        };

        [$notifier] = pnNotifier(
            ['https://push.example.org/a' => 201, 'https://push.example.org/b' => 201, 'https://push.example.org/c' => 201],
            maxSeconds: 0.15,
            transport: $pomaly,
        );

        $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1');

        assertTrue(count($sent) < 3, 'po překročení rozpočtu se zbylá zařízení přeskočí, bylo ' . count($sent));
    },

    'transport, který spadne, nic neshodí' => function (): void {
        [$notifier, , $subs] = pnNotifier(
            ['https://push.example.org/a' => 201],
            transport: static fn (): array => throw new RuntimeException('síť je pryč'),
        );

        assertSame(0, $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1'));
        assertSame(1, $subs->count(), 'jedna chyba odběr ještě neruší');
    },

    'bez vyrobených klíčů se mlčí' => function (): void {
        [$notifier] = pnNotifier(['https://push.example.org/a' => 201], withKeys: false);

        assertFalse($notifier->ready());
        assertSame(0, $notifier->send('sites', 'Web nedostupný', 'text', 'weby/1'));

        // Zkouška musí říct proč, ne jen „neodešlo nic" — jinak nezbývá než
        // hledat v logu na serveru.
        $result = $notifier->test();
        assertSame(0, $result['sent']);
        assertContainsString('podpisový klíč', $result['error']);
    },

    'zkušební upozornění jde mimo skupiny i mimo tlumení' => function (): void {
        [$notifier, $settings, , $sent] = pnNotifier(['https://push.example.org/a' => 201]);

        // I když je všechno vypnuté a strop chyb vyčerpaný, zkouška musí projít.
        $settings->set('push_on_sites', '0');
        $settings->set('push_on_error', '0');

        assertSame(1, $notifier->test()['sent']);
        assertSame(1, $notifier->test()['sent'], 'zkoušku si člověk vyžádal, netlumí se');
        assertSame(2, count($sent));
    },

    'zkouška bez zařízení i s odmítnutou zprávou řekne, co se stalo' => function (): void {
        // Žádné zařízení: rozeslat není kam a je to jiná porucha než chyba
        // push služby — každá se řeší jinde.
        [$prazdny] = pnNotifier([]);
        $result = $prazdny->test();
        assertSame(0, $result['sent']);
        assertSame(0, $result['devices']);
        assertSame('', $result['error'], 'bez zařízení není co hlásit jako chybu');

        // Push služba odmítla (typicky zablokovaný odchozí provoz hostingu
        // nebo neplatný podpis) — text odpovědi musí projít až do hlášky.
        [$odmitnuty] = pnNotifier(
            ['https://push.example.org/a' => 201],
            transport: static fn (): array => ['status' => 403, 'error' => 'HTTP 403: Forbidden'],
        );
        $result = $odmitnuty->test();
        assertSame(0, $result['sent']);
        assertSame(1, $result['devices']);
        assertContainsString('403', $result['error']);
    },

    'zpráva nese odkaz v rámci správy a zkrácené tělo' => function (): void {
        [$notifier, , , $sent] = pnNotifier(['https://push.example.org/a' => 201]);

        $notifier->send('sites', 'Web nedostupný', str_repeat('a', 400), 'weby/1042');

        // Tělo je zašifrované, takže se kouká na to, co se dá: délku a to,
        // že vůbec odešlo. Obsah payloadu kryje WebPushTest dešifrováním.
        assertSame(1, count($sent));
        assertTrue(strlen($sent[0]['body']) > 0);
    },

    'VapidKeys: pár se vyrobí jednou a pak už zůstává' => function (): void {
        $db = freshTestDb();
        $settings = new Settings($db, new Secrets(PN_APP_KEY));
        $keys = new VapidKeys($settings, 'https://sprava.test');

        assertFalse($keys->ready());
        assertSame(null, $keys->vapid());

        assertTrue($keys->ensure(), 'napoprvé se pár vyrábí');
        assertTrue($keys->ready());

        $public = $keys->publicKey();
        assertTrue($public !== '');

        assertFalse($keys->ensure(), 'podruhé už není co vyrábět');
        assertSame($public, $keys->publicKey(), 'výměna páru by odhlásila všechny telefony');

        $vapid = $keys->vapid();
        assertTrue($vapid !== null);
        assertSame($public, $vapid->publicKey());

        // Soukromý klíč leží v nastavení šifrovaně, ven jdou jen poslední 4 znaky.
        assertTrue($settings->get(VapidKeys::PRIVATE_KEY) !== '');
        assertSame(4, mb_strlen($settings->secretHint(VapidKeys::PRIVATE_KEY)));
    },

    'VapidKeys: subject bere adresu odesílatele z Nastavení → E-mail' => function (): void {
        $db = freshTestDb();
        $settings = new Settings($db, new Secrets(PN_APP_KEY));

        $settings->set('mail_from_address', 'noreply@dispu.cz');
        (new VapidKeys($settings, 'https://sprava.test'))->ensure();
        assertSame('mailto:noreply@dispu.cz', $settings->get(VapidKeys::SUBJECT));

        // Bez nastavené pošty se odvodí z adresy správy.
        $db2 = freshTestDb();
        $settings2 = new Settings($db2, new Secrets(PN_APP_KEY));
        (new VapidKeys($settings2, 'https://sprava.dispu.cz'))->ensure();
        assertSame('mailto:noreply@sprava.dispu.cz', $settings2->get(VapidKeys::SUBJECT));
    },

    'prohlížeč nahlásí odběr serveru — JSON tělo, token v hlavičce' => function (): void {
        [$kernel, $token] = pnKernel();

        $odber = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abcdef123456',
            'keys' => ['p256dh' => PN_P256DH, 'auth' => PN_AUTH],
        ];

        [$status, $data] = pnPost($kernel, '/nastaveni/oznameni/prihlasit', $odber, $token);
        assertSame(200, $status);
        assertSame('success', $data['status']);
        assertSame(1, $kernel->pushSubscriptions()->count());

        // Nesmyslné klíče se odmítnou hned — uložený vadný odběr by tiše
        // nedoručoval a poznalo by se to až chybějícím upozorněním.
        [$status] = pnPost($kernel, '/nastaveni/oznameni/prihlasit',
            ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => 'AAAA', 'auth' => 'x']], $token);
        assertSame(422, $status);

        // Token v hlavičce hlídá stejné pravidlo jako u formulářů.
        [$status] = pnPost($kernel, '/nastaveni/oznameni/prihlasit', $odber, '');
        assertSame(419, $status);

        [$status] = pnPost($kernel, '/nastaveni/oznameni/odhlasit', ['endpoint' => $odber['endpoint']], $token);
        assertSame(200, $status);
        assertSame(0, $kernel->pushSubscriptions()->count());
    },
];
