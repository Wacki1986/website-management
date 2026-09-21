<?php

declare(strict_types=1);

use App\Core\Db\Connection;
use App\Core\Notifications\PushSubscriptions;
use App\Core\Notifications\WebPush\Keys;
use App\Core\Notifications\WebPush\MessageEncryption;
use App\Core\Notifications\WebPush\Vapid;
use App\Core\Notifications\WebPush\WebPush;

/**
 * Web push — vlastní kryptografie, převzatá z klientské aplikace.
 *
 * Krypto se nepíše „od oka": šifrování se porovnává s referenčním vektorem
 * z RFC 8291 §5 (stejné klíče, stejná sůl ⇒ stejné bajty) a VAPID podpis
 * se ověřuje zpětně přes OpenSSL veřejným klíčem. Žádná push služba se
 * nevolá — `WebPush` dostane podstrčený transport.
 *
 * Sada je skoro doslovná kopie `../aplikace/dev/tests/WebPushTest.php`.
 * Rozdíl je jen v tom, co se testuje kolem: správa nemá tabulku oznámení
 * ani `PushDispatcher`, zato má `forActiveUsers()` (rozesílá všem účtům)
 * a jiné sloupce v `users`.
 */

/** Referenční hodnoty z RFC 8291, Appendix A / §5. */
const RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const RFC_UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
const RFC_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const RFC_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
const RFC_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
const RFC_PLAINTEXT = 'When I grow up, I want to be a watermelon';
const RFC_BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

/**
 * Dešifrování podle RFC 8291 — jen pro test, správa ho nepotřebuje.
 * Ověřuje, že i s **náhodným** klíčem a solí vznikne zpráva, kterou by
 * prohlížeč přečetl; vektor výš kryje jen jednu konkrétní kombinaci.
 */
function rfc8291Decrypt(string $body, string $uaPrivate, string $uaPublic, string $auth): string
{
    $salt = substr($body, 0, 16);
    $keyLength = ord($body[20]);
    $asPublic = substr($body, 21, $keyLength);
    $ciphertext = substr($body, 21 + $keyLength);

    $private = openssl_pkey_get_private(Keys::privatePem(Keys::base64UrlDecode($uaPrivate), Keys::base64UrlDecode($uaPublic)));
    $public = openssl_pkey_get_public(Keys::publicPem($asPublic));
    $secret = openssl_pkey_derive($public, $private);

    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\x00" . Keys::base64UrlDecode($uaPublic) . $asPublic, Keys::base64UrlDecode($auth));
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $tag = substr($ciphertext, -16);
    $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

    return rtrim((string) $plain, "\x02");
}

/** Převod `r || s` zpět na DER, aby šel podpis ověřit přes `openssl_verify`. */
function rawSignatureToDer(string $raw): string
{
    $int = static function (string $x): string {
        $x = ltrim($x, "\x00");
        if (ord($x[0]) > 127) {
            $x = "\x00" . $x;
        }

        return "\x02" . chr(strlen($x)) . $x;
    };
    $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));

    return "\x30" . chr(strlen($body)) . $body;
}

/** Účet ve schématu správy — jiné sloupce než v klientské aplikaci. */
function pushTestUser(Connection $db, string $name, bool $active = true): int
{
    $now = date('Y-m-d H:i:s');

    return $db->insert('users', [
        'username' => $name,
        'email' => $name . '@test.cz',
        'name' => $name,
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'is_active' => $active ? 1 : 0,
        'theme' => 'auto',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

return [
    'šifrování sedí na referenční vektor z RFC 8291' => function (): void {
        $body = MessageEncryption::encrypt(
            RFC_PLAINTEXT,
            RFC_UA_PUBLIC,
            RFC_AUTH,
            ['public' => Keys::base64UrlDecode(RFC_AS_PUBLIC), 'private' => Keys::base64UrlDecode(RFC_AS_PRIVATE)],
            Keys::base64UrlDecode(RFC_SALT),
        );

        assertSame(RFC_BODY, Keys::base64UrlEncode($body));
    },

    'zpráva s náhodným klíčem a solí jde dešifrovat klíčem prohlížeče' => function (): void {
        $message = '{"title":"#POD-1042","body":"Nejde mi přihlášení","url":"/podpora/1042"}';
        $body = MessageEncryption::encrypt($message, RFC_UA_PUBLIC, RFC_AUTH);

        assertSame(16 + 4 + 1 + 65 + strlen($message) + 1 + 16, strlen($body), 'sůl · rs · délka · klíč · šifra · značka');
        assertSame(4096, unpack('N', substr($body, 16, 4))[1], 'velikost záznamu');
        assertSame($message, rfc8291Decrypt($body, RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH));

        // Dvě šifrování téže zprávy se liší (náhodný klíč i sůl).
        assertTrue($body !== MessageEncryption::encrypt($message, RFC_UA_PUBLIC, RFC_AUTH));
    },

    'vadné klíče odběru se odmítnou, dlouhá zpráva taky' => function (): void {
        assertThrows(RuntimeException::class, fn () => MessageEncryption::encrypt('x', 'AAAA', RFC_AUTH));
        assertThrows(RuntimeException::class, fn () => MessageEncryption::encrypt('x', RFC_UA_PUBLIC, 'kratke'));
        assertThrows(RuntimeException::class, fn () => MessageEncryption::encrypt(str_repeat('a', 4090), RFC_UA_PUBLIC, RFC_AUTH));
    },

    'VAPID: vygenerovaný pár podepíše JWT, které ověří veřejný klíč' => function (): void {
        $keys = Vapid::generateKeys();
        assertSame(65, strlen(Keys::base64UrlDecode($keys['public_key'])));
        assertSame(32, strlen(Keys::base64UrlDecode($keys['private_key'])));

        $vapid = Vapid::fromConfig($keys + ['subject' => 'mailto:spravce@dispu.cz']);
        assertTrue($vapid !== null);

        $now = 1_700_000_000;
        $header = $vapid->authorization('https://fcm.googleapis.com/fcm/send/abc123', $now);
        assertTrue(str_starts_with($header, 'vapid t='));
        assertContainsString(', k=' . $keys['public_key'], $header);

        $token = substr($header, strlen('vapid t='), strpos($header, ', k=') - strlen('vapid t='));
        [$h, $c, $s] = explode('.', $token);

        assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Keys::base64UrlDecode($h), true));
        $claims = json_decode(Keys::base64UrlDecode($c), true);
        assertSame('https://fcm.googleapis.com', $claims['aud'], 'aud je původ služby, ne celý endpoint');
        assertSame('mailto:spravce@dispu.cz', $claims['sub']);
        assertTrue($claims['exp'] > $now && $claims['exp'] <= $now + 24 * 3600, 'exp do 24 h');

        $public = openssl_pkey_get_public(Keys::publicPem(Keys::base64UrlDecode($keys['public_key'])));
        assertSame(1, openssl_verify("$h.$c", rawSignatureToDer(Keys::base64UrlDecode($s)), $public, OPENSSL_ALGO_SHA256));
    },

    'VAPID: bez klíčů nebo s nesmyslným subjectem se push nezapne' => function (): void {
        assertSame(null, Vapid::fromConfig([]));
        assertSame(null, Vapid::fromConfig(['public_key' => 'x', 'private_key' => '', 'subject' => 'mailto:a@b.cz']));
        assertSame(null, Vapid::fromConfig(Vapid::generateKeys() + ['subject' => 'spravce@dispu.cz']), 'subject bez mailto:');
    },

    'WebPush: složí požadavek a přeloží odpověď služby na OK / GONE / FAILED' => function (): void {
        $vapid = Vapid::fromConfig(Vapid::generateKeys() + ['subject' => 'mailto:a@b.cz']);
        $seen = [];
        $status = 201;

        $push = new WebPush($vapid, function (string $endpoint, array $headers, string $body) use (&$seen, &$status): array {
            $seen = ['endpoint' => $endpoint, 'headers' => $headers, 'body' => $body];

            return ['status' => $status, 'error' => ''];
        });

        $subscription = ['endpoint' => 'https://push.example.org/s/1', 'p256dh' => RFC_UA_PUBLIC, 'auth' => RFC_AUTH];

        $ok = $push->send($subscription, ['title' => 'Ahoj', 'body' => 'Text', 'url' => '/x']);
        assertSame(WebPush::OK, $ok['result']);
        assertSame('https://push.example.org/s/1', $seen['endpoint']);
        $joined = implode("\n", $seen['headers']);
        assertContainsString('Content-Encoding: aes128gcm', $joined);
        assertContainsString('Authorization: vapid t=', $joined);
        assertContainsString('TTL: ', $joined);
        assertContainsString('Content-Length: ' . strlen($seen['body']), $joined);
        assertSame(
            '{"title":"Ahoj","body":"Text","url":"/x"}',
            rfc8291Decrypt($seen['body'], RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH),
        );

        $status = 410;
        assertSame(WebPush::GONE, $push->send($subscription, ['title' => 'x'])['result']);
        $status = 500;
        assertSame(WebPush::FAILED, $push->send($subscription, ['title' => 'x'])['result']);

        // Vadný odběr je GONE bez volání služby.
        $seen = [];
        assertSame(WebPush::GONE, $push->send(['endpoint' => 'https://p/1', 'p256dh' => 'AAAA', 'auth' => RFC_AUTH], ['title' => 'x'])['result']);
        assertSame([], $seen);
    },

    'PushSubscriptions: uložení, přepis cizího endpointu, seznam a úklid po chybách' => function (): void {
        $db = freshTestDb();
        $subs = new PushSubscriptions($db);
        $anna = pushTestUser($db, 'anna');
        $petr = pushTestUser($db, 'petr');

        $id = $subs->save($anna, 'https://push.example.org/a', RFC_UA_PUBLIC, RFC_AUTH, 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537.36');
        assertSame($id, $subs->save($anna, 'https://push.example.org/a', RFC_UA_PUBLIC, RFC_AUTH, 'ua'), 'stejný endpoint = stejný řádek');
        assertSame(1, count($subs->forUser($anna)));

        // Sdílený telefon: přihlásil se Petr → zařízení je teď jeho.
        $subs->save($petr, 'https://push.example.org/a', RFC_UA_PUBLIC, RFC_AUTH, 'ua');
        assertSame(0, count($subs->forUser($anna)));
        assertSame(1, count($subs->forUser($petr)));

        assertFalse($subs->remove($anna, $id), 'cizí odběr nejde odebrat');
        assertFalse($subs->removeByEndpoint($anna, 'https://push.example.org/a'));

        for ($i = 0; $i < PushSubscriptions::MAX_FAILURES - 1; $i++) {
            assertFalse($subs->markFailed($id, $i));
        }
        assertTrue($subs->markFailed($id, PushSubscriptions::MAX_FAILURES - 1), 'pátá chyba odběr smaže');
        assertSame(0, $subs->count());

        assertSame('Android · Chrome', PushSubscriptions::deviceLabel('Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537.36'));
        assertSame('iPhone · Safari', PushSubscriptions::deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1'));
    },

    'PushSubscriptions: rozesílá se všem aktivním účtům, pozastavené vynechá' => function (): void {
        $db = freshTestDb();
        $subs = new PushSubscriptions($db);
        $anna = pushTestUser($db, 'anna');
        $byvaly = pushTestUser($db, 'byvaly-kolega', active: false);

        $subs->save($anna, 'https://push.example.org/anna', RFC_UA_PUBLIC, RFC_AUTH, 'ua');
        $subs->save($byvaly, 'https://push.example.org/byvaly', RFC_UA_PUBLIC, RFC_AUTH, 'ua');

        $devices = $subs->forActiveUsers(20);

        assertSame(1, count($devices), 'pozastavený účet už upozornění dostávat nemá');
        assertSame('https://push.example.org/anna', $devices[0]['endpoint']);
    },
];
