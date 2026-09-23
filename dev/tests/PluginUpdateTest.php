<?php

declare(strict_types=1);

/**
 * Akce na webu ze správy — celá cesta proti falešnému webu: podepsaný
 * POST z `PluginClient`, formuláře na záložce Pluginy a Přehledu, akce
 * `SiteActionController` (aktualizace a mazání pluginů, aktualizace
 * WordPressu) a čerstvý souhrn po nich (nová verze, událost v historii,
 * záznam v auditu).
 *
 * Falešný web ověřuje podpis vlastním kódem (vzorec z pluginu), takže
 * test hlídá, že hub a plugin podepisují stejně.
 */

use App\Core\Kernel;
use App\Core\Monitor\PluginClient;
use App\Core\Sites\SiteActions;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/FakeInstance.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const PU_KEY = 'mg_live_TESTKEY0000000000000000000000';

/**
 * @param array<string, string> $env
 * @param callable(string, string): void $test dostane adresu webu a soubor se stavem
 */
function withUpdatableWp(int $port, array $env, callable $test): void
{
    $state = sys_get_temp_dir() . '/fake-wp-state-' . $port . '-' . getmypid();
    puForget($state);

    if (!FakeInstance::start($port, $env + ['FAKE_WP_KEY' => PU_KEY, 'FAKE_WP_STATE' => $state], 'fake-wp-site.php')) {
        skip('falešný web nenastartoval: ' . FakeInstance::lastError());
    }

    try {
        $test('http://127.0.0.1:' . $port, $state);
    } finally {
        FakeInstance::stop();
        puForget($state);
    }
}

/** Zapomenout, co falešný web „udělal" (aktualizace, smazání, jádro). */
function puForget(string $state): void
{
    foreach ([$state, $state . '.deleted', $state . '.core'] as $file) {
        @unlink($file);
    }
}

/**
 * Kernel s přihlášeným správcem a webem napojeným na falešný WordPress
 * (první souhrn už načtený).
 *
 * @return array{0: Kernel, 1: string, 2: int} kernel, CSRF token, id webu
 */
function puKernel(string $siteUrl): array
{
    [$kernel, $token] = loggedInKernel('Správce', ['allow_insecure_sites' => true]);

    $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $siteUrl]);
    $kernel->sites()->setApiKey($siteId, PU_KEY);
    $kernel->monitor()->checkOne($kernel->sites()->find($siteId));

    return [$kernel, $token, $siteId];
}

/** @return array<string, mixed>|null */
function puPlugin(Kernel $kernel, int $siteId, string $file): ?array
{
    return $kernel->db()->selectOne('SELECT * FROM site_plugins WHERE site_id = :id AND file = :file', ['id' => $siteId, 'file' => $file]);
}

return [
    'podpis: stejný vzorec jako plugin, jiné tělo nebo čas = jiný podpis' => function (): void {
        $body = '{"plugins":["elementor/elementor.php"]}';
        $expected = hash_hmac('sha256', "POST\n/mediagrafik-monitor/v1/actions/plugin-update\n1758000000\n" . $body, PU_KEY);

        assertSame($expected, PluginClient::signature('POST', 'actions/plugin-update', 1758000000, $body, PU_KEY));
        assertTrue($expected !== PluginClient::signature('POST', 'actions/plugin-update', 1758000001, $body, PU_KEY));
        assertTrue($expected !== PluginClient::signature('POST', 'actions/plugin-update', 1758000000, '{"plugins":[]}', PU_KEY));
    },

    'klient: podepsaný POST vrátí výsledek po pluginech' => function (): void {
        withUpdatableWp(8231, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->updatePlugins($url, PU_KEY, ['elementor/elementor.php', 'premium/premium.php']);

            assertTrue($result['ok'], (string) $result['error']);
            assertSame('updated', $result['data']['plugins'][0]['status']);
            assertSame('3.24.0', $result['data']['plugins'][0]['to']);
            assertSame('failed', $result['data']['plugins'][1]['status']);
        });
    },

    'klient: bez hezkých adres projde POST záložkou ?rest_route= se stejným podpisem' => function (): void {
        withUpdatableWp(8232, ['FAKE_WP_MODE' => 'no_pretty', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->updatePlugins($url, PU_KEY, ['elementor/elementor.php']);

            assertTrue($result['ok'], (string) $result['error']);
            assertSame('updated', $result['data']['plugins'][0]['status']);
        });
    },

    'klient: chyba akce z pluginu se ukáže jeho vlastní zprávou' => function (): void {
        withUpdatableWp(8233, ['FAKE_WP_MODE' => 'no_filemods', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url): void {
            $result = (new PluginClient(timeout: 5))->updatePlugins($url, PU_KEY, ['elementor/elementor.php']);

            assertFalse($result['ok']);
            assertSame('error', $result['code']);
            assertContainsString('DISALLOW_FILE_MODS', (string) $result['error']);
        });
    },

    'záložka Pluginy: zaškrtávátko a řádkové tlačítko jen u pluginu s aktualizací' => function (): void {
        withUpdatableWp(8234, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url): void {
            [$kernel, , $siteId] = puKernel($url);
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();

            assertContainsString('name="plugins[]" value="elementor/elementor.php"', $html);
            assertContainsString('name="plugin" value="elementor/elementor.php"', $html);
            assertFalse(str_contains($html, 'value="woocommerce/woocommerce.php"'), 'WooCommerce aktualizaci nemá');
            assertContainsString('action="/weby/' . $siteId . '/pluginy/aktualizovat"', $html);

            Urls::reset();
        });
    },

    'akce: aktualizuje jen pluginy s novou verzí, pak načte čerstvá data do tabulky, historie i auditu' => function (): void {
        withUpdatableWp(8235, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = puKernel($url);

            assertSame(1, (int) puPlugin($kernel, $siteId, 'elementor/elementor.php')['has_update']);

            // WooCommerce aktualizaci nemá — na web se vůbec nepošle.
            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/aktualizovat', [
                '_token' => $token,
                'plugins' => ['elementor/elementor.php', 'woocommerce/woocommerce.php'],
            ]);

            assertSame(302, $response->status());
            assertTrue(is_file($state), 'Falešný web aktualizaci nedostal');

            $elementor = puPlugin($kernel, $siteId, 'elementor/elementor.php');
            assertSame('3.24.0', $elementor['version']);
            assertSame(0, (int) $elementor['has_update']);

            $event = $kernel->db()->selectOne("SELECT * FROM events WHERE site_id = :id AND message LIKE 'Elementor aktualizován%'", ['id' => $siteId]);
            assertTrue($event !== null, 'Historie nemá záznam o aktualizaci');

            $audit = $kernel->db()->selectOne("SELECT * FROM audit_log WHERE action = 'pluginy-aktualizace'");
            assertTrue($audit !== null);
            assertSame(1, (int) $audit['success']);
            assertContainsString('Elementor 3.23.1 → 3.24.0', (string) $audit['description']);
            assertFalse(str_contains((string) $audit['description'], 'WooCommerce'));

            Urls::reset();
        });
    },

    'MEDIAGRAFIK Monitor: správa nabídne svou novější verzi, i když o ní web ještě neví' => function (): void {
        withUpdatableWp(8237, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url): void {
            [$kernel, , $siteId] = puKernel($url);
            $dir = $kernel->storagePath('plugin');
            @mkdir($dir, 0775, true);
            $monitor = 'name="plugin" value="mediagrafik-monitor/mediagrafik-monitor.php"';

            try {
                // Správa rozdává stejnou verzi, jakou web má — nic k aktualizaci.
                file_put_contents($dir . '/plugin-info.json', json_encode(['version' => '1.1.0']));
                assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body(), $monitor));

                // Nová verze ve správě: řádek je aktualizovatelný hned, bez čekání na web.
                file_put_contents($dir . '/plugin-info.json', json_encode(['version' => '1.2.0']));
                $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
                assertContainsString($monitor, $html);
                assertContainsString('1.2.0', $html);
            } finally {
                @unlink($dir . '/plugin-info.json');
            }

            Urls::reset();
        });
    },

    'akce: starší plugin na webu (1.0.0) se ani nezkouší' => function (): void {
        withUpdatableWp(8236, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.0.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = puKernel($url);

            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
            assertContainsString('potřebuje MEDIAGRAFIK Monitor 1.1.0', $html);
            assertFalse(str_contains($html, 'name="plugin" value='), 'Řádkové tlačítko nemá být aktivní');

            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/aktualizovat', [
                '_token' => $token,
                'plugin' => 'elementor/elementor.php',
            ]);

            assertSame(302, $response->status());
            assertFalse(is_file($state), 'Požadavek na web nesměl odejít');
            assertSame('3.23.1', puPlugin($kernel, $siteId, 'elementor/elementor.php')['version']);

            Urls::reset();
        });
    },

    'co jde ze správy: verze pluginu na webu, mazat jen neaktivní a nikdy MEDIAGRAFIK Monitor' => function (): void {
        $site = ['api_key' => 'x'];

        assertSame(null, SiteActions::blocked($site, ['plugin_version' => '1.2.0'], PluginClient::ACTION_CORE_UPDATE));
        assertSame(null, SiteActions::blocked($site, ['plugin_version' => '1.1.0'], PluginClient::ACTION_PLUGIN_UPDATE));
        assertContainsString('potřebuje MEDIAGRAFIK Monitor 1.2.0 (web má 1.1.0)', (string) SiteActions::blocked($site, ['plugin_version' => '1.1.0'], PluginClient::ACTION_PLUGIN_DELETE));
        assertContainsString('nemá napojený', (string) SiteActions::blocked(['api_key' => ''], ['plugin_version' => '1.2.0'], PluginClient::ACTION_CORE_UPDATE));

        assertTrue(SiteActions::isDeletable(['is_active' => 0, 'file' => 'contact-form-7/wp-contact-form-7.php']));
        assertFalse(SiteActions::isDeletable(['is_active' => 1, 'file' => 'woocommerce/woocommerce.php']));
        assertFalse(SiteActions::isDeletable(['is_active' => 0, 'file' => 'mediagrafik-monitor-1.0.0/mediagrafik-monitor.php']));
    },

    'mazání: odkaz jen u neaktivního pluginu, potvrzení, smazání a čerstvá data' => function (): void {
        withUpdatableWp(8238, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.2.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = puKernel($url);
            $cf7 = 'contact-form-7/wp-contact-form-7.php';

            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
            assertContainsString('pluginy/smazat?plugin=contact-form-7%2Fwp-contact-form-7.php', $html);
            assertFalse(str_contains($html, 'smazat?plugin=woocommerce'), 'Aktivní plugin se mazat nenabízí');

            $confirm = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy/smazat', [], ['plugin' => $cf7]);
            assertSame(200, $confirm->status());
            assertContainsString('Smazat plugin Contact Form 7', $confirm->body());

            // Aktivní plugin: požadavek na web vůbec neodejde.
            kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/smazat', ['_token' => $token, 'plugin' => 'woocommerce/woocommerce.php']);
            assertFalse(is_file($state . '.deleted'));

            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/smazat', ['_token' => $token, 'plugin' => $cf7]);
            assertSame(302, $response->status());
            assertTrue(is_file($state . '.deleted'), 'Falešný web mazání nedostal');
            assertSame(null, puPlugin($kernel, $siteId, $cf7));

            $event = $kernel->db()->selectOne("SELECT * FROM events WHERE site_id = :id AND message = 'Contact Form 7 odstraněn'", ['id' => $siteId]);
            assertTrue($event !== null, 'Historie nemá záznam o smazání');
            $audit = $kernel->db()->selectOne("SELECT * FROM audit_log WHERE action = 'plugin-smazani'");
            assertTrue($audit !== null && (int) $audit['success'] === 1);

            Urls::reset();
        });
    },

    'mazání: plugin 1.1.0 na webu akci nezná — odkaz neaktivní, požadavek neodejde' => function (): void {
        withUpdatableWp(8239, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.1.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = puKernel($url);

            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
            assertFalse(str_contains($html, 'pluginy/smazat?plugin='));
            assertContainsString('potřebuje MEDIAGRAFIK Monitor 1.2.0', $html);

            kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/smazat', ['_token' => $token, 'plugin' => 'contact-form-7/wp-contact-form-7.php']);
            assertFalse(is_file($state . '.deleted'));

            Urls::reset();
        });
    },

    'WordPress: odkaz na Přehledu, potvrzení konkrétní verze, aktualizace a čerstvá data' => function (): void {
        withUpdatableWp(8240, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.2.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = puKernel($url);

            assertContainsString('href="/weby/' . $siteId . '/wordpress"', kernelRequest($kernel, 'GET', '/weby/' . $siteId)->body());

            $confirm = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/wordpress');
            assertSame(200, $confirm->status());
            assertContainsString('6.8.2 → 6.9', $confirm->body());
            assertContainsString('name="version" value="6.9"', $confirm->body());

            // Potvrzená verze nesedí s tím, co web hlásí — neodejde nic.
            kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/wordpress', ['_token' => $token, 'version' => '6.8.3']);
            assertFalse(is_file($state . '.core'));

            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/wordpress', ['_token' => $token, 'version' => '6.9']);
            assertSame(302, $response->status());
            assertTrue(is_file($state . '.core'), 'Falešný web aktualizaci jádra nedostal');

            $snapshot = $kernel->snapshots()->snapshot($siteId);
            assertSame('6.9', $snapshot['wp_version']);
            assertSame(null, $snapshot['wp_update_version']);

            $event = $kernel->db()->selectOne("SELECT * FROM events WHERE site_id = :id AND message LIKE 'WordPress aktualizován%'", ['id' => $siteId]);
            assertTrue($event !== null, 'Historie nemá záznam o aktualizaci WordPressu');
            $audit = $kernel->db()->selectOne("SELECT * FROM audit_log WHERE action = 'wordpress-aktualizace'");
            assertTrue($audit !== null && (int) $audit['success'] === 1);
            assertContainsString('6.8.2 → 6.9', (string) $audit['description']);

            // Aktuální web už odkaz nemá a potvrzovací stránka vrací zpět.
            assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $siteId)->body(), 'href="/weby/' . $siteId . '/wordpress"'));
            assertSame(302, kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/wordpress')->status());

            Urls::reset();
        });
    },

    'wp-admin: s účtem studia přihlásí jednorázovým odkazem, bez účtu otevře přihlášení' => function (): void {
        withUpdatableWp(8241, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.3.0'], function (string $url): void {
            [$kernel, $token, $siteId] = puKernel($url);
            $location = static fn ($response): string => (string) ($response->headers()['Location'] ?? '');

            // Bez nastaveného účtu: obyčejný odkaz na přihlášení.
            assertContainsString('href="' . $url . '/wp-admin/"', kernelRequest($kernel, 'GET', '/weby/' . $siteId)->body());
            assertSame($url . '/wp-admin/', $location(kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/prihlasit', ['_token' => $token])));

            // Výchozí účet studia z nastavení: tlačítko posílá formulář do nového okna.
            $kernel->settings()->set(SiteActions::LOGIN_USER_SETTING, 'mediagrafik');
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId)->body();
            assertContainsString('action="/weby/' . $siteId . '/prihlasit" target="_blank"', $html);
            assertContainsString('Přihlásit jako mediagrafik', $html);

            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/prihlasit', ['_token' => $token]);
            assertSame(302, $response->status());
            assertSame($url . '/?mg_login=' . str_repeat('ab', 32), $location($response));

            // Vlastní účet u webu přebije výchozí; neexistující účet = zpět do správy s chybou.
            $kernel->sites()->update($siteId, ['wp_login_user' => 'neexistuje']);
            $failed = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/prihlasit', ['_token' => $token]);
            assertSame('/weby/' . $siteId, $location($failed));

            Urls::reset();
        });
    },

    'wp-admin: plugin starší než 1.3.0 přihlášení nezná — zůstane obyčejný odkaz' => function (): void {
        withUpdatableWp(8242, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_RELEASE' => '1.2.0'], function (string $url): void {
            [$kernel, $token, $siteId] = puKernel($url);
            $kernel->settings()->set(SiteActions::LOGIN_USER_SETTING, 'mediagrafik');

            assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $siteId)->body(), '/prihlasit"'));
            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/prihlasit', ['_token' => $token]);
            assertSame($url . '/wp-admin/', (string) ($response->headers()['Location'] ?? ''));

            Urls::reset();
        });
    },
];
