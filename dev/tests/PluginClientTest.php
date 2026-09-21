<?php

declare(strict_types=1);

/**
 * Klient pluginu proti falešnému WordPress webu (`fixtures/fake-wp-site.php`).
 *
 * Každý režim fixture odpovídá jednomu stavu `api_status`, který hub
 * rozlišuje: ok · bad_key · no_plugin · error (HTML) · záložka bez hezkých
 * adres · nedostupný.
 */

use App\Core\Monitor\PluginClient;

require_once __DIR__ . '/fixtures/FakeInstance.php';

const FAKE_WP_KEY = 'mg_live_TESTKEY0000000000000000000000';

function withFakeWp(int $port, array $env, callable $test): void
{
    if (!FakeInstance::start($port, $env, 'fake-wp-site.php')) {
        skip('falešný web nenastartoval: ' . FakeInstance::lastError());
    }

    try {
        $test('http://127.0.0.1:' . $port);
    } finally {
        FakeInstance::stop();
    }
}

return [
    'správný klíč vrátí souhrn v obálce' => function (): void {
        withFakeWp(8201, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_KEY' => FAKE_WP_KEY], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->summary($url, FAKE_WP_KEY);

            assertTrue($result['ok'], (string) $result['error']);
            assertSame('ok', $result['code']);
            assertSame('1.0.0', $result['plugin_version']);
            assertSame('6.8.2', $result['data']['wordpress']['version']);
            assertSame(3, count($result['data']['plugins']['items']));

            $ping = (new PluginClient(timeout: 5))->ping($url, FAKE_WP_KEY);
            assertSame('ok', $ping['code']);
        });
    },

    'špatný klíč = bad_key (odmítnutí zabalené WordPressem)' => function (): void {
        withFakeWp(8202, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_KEY' => FAKE_WP_KEY], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->summary($url, 'mg_live_JINYKLIC000000000000000000000');

            assertFalse($result['ok']);
            assertSame('bad_key', $result['code']);
            assertSame(401, $result['status']);
            assertContainsString('Neplatný', (string) $result['error']);
        });
    },

    'web bez pluginu = no_plugin' => function (): void {
        withFakeWp(8203, ['FAKE_WP_MODE' => 'no_plugin'], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->summary($url, FAKE_WP_KEY);

            assertSame('no_plugin', $result['code']);
            assertContainsString('není nainstalovaný', (string) $result['error']);
        });
    },

    'HTML místo JSONu = error' => function (): void {
        withFakeWp(8204, ['FAKE_WP_MODE' => 'html'], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->summary($url, FAKE_WP_KEY);

            assertSame('error', $result['code']);
            assertSame(500, $result['status']);
        });
    },

    'bez hezkých adres projde záložka ?rest_route=' => function (): void {
        withFakeWp(8205, ['FAKE_WP_MODE' => 'no_pretty', 'FAKE_WP_KEY' => FAKE_WP_KEY], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->summary($url, FAKE_WP_KEY);

            assertTrue($result['ok'], (string) $result['error']);
            assertSame('ok', $result['code']);
        });
    },

    'nedostupný web = unreachable, ne error' => function (): void {
        $result = (new PluginClient(timeout: 2))->summary('http://127.0.0.1:8299', FAKE_WP_KEY);

        assertFalse($result['ok']);
        assertSame('unreachable', $result['code']);
    },

    'souhrn z více webů najednou drží klíče vstupu' => function (): void {
        withFakeWp(8206, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_KEY' => FAKE_WP_KEY], function (string $url): void {
            $results = (new PluginClient(timeout: 5))->summaryMany([
                7 => ['url' => $url, 'key' => FAKE_WP_KEY],
                9 => ['url' => $url, 'key' => 'mg_live_JINYKLIC000000000000000000000'],
                11 => ['url' => 'http://127.0.0.1:8299', 'key' => FAKE_WP_KEY],
            ]);

            assertSame('ok', $results[7]['code']);
            assertSame('bad_key', $results[9]['code']);
            assertSame('unreachable', $results[11]['code']);
        });
    },

    'adresa endpointu: hezká i záložní' => function (): void {
        assertSame('https://a.cz/wp-json/mediagrafik-monitor/v1/summary', PluginClient::endpointUrl('https://a.cz/', 'summary'));
        assertSame('https://a.cz/?rest_route=/mediagrafik-monitor/v1/ping', PluginClient::endpointUrl('https://a.cz', 'ping', true));
    },
];
