<?php

declare(strict_types=1);

/**
 * Záložka Služby v detailu webu — nasazené služby a kroky po spuštění.
 */

use App\Core\Auth\UserRepository;
use App\Core\Http\Request;
use App\Core\Kernel;
use App\Core\Sites\SiteServices;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/**
 * Přepnutí tak, jak ho posílá site-services.js — s odpovědí v JSON.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function servicesJsonToggle(Kernel $kernel, int $siteId, array $body): array
{
    $response = $kernel->handle(new Request(
        method: 'POST',
        path: '/weby/' . $siteId . '/sluzby',
        query: [],
        body: $body,
        files: [],
        cookies: [],
        headers: ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    ));

    return ['status' => $response->status(), 'data' => json_decode($response->body(), true)];
}

return [
    'služby: nový web má vše nezaškrtnuté, záložka ukáže číselník i optimalizace podle zadání' => function (): void {
        [$kernel] = loggedInKernel();
        $id = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz', 'launched_on' => '2026-10-13']);

        $html = kernelRequest($kernel, 'GET', '/weby/' . $id . '/sluzby')->body();

        assertContainsString('Nasazené služby', $html);
        assertContainsString('0 z 8 nasazeno', $html);
        assertContainsString('Google Search Console', $html);
        assertContainsString('Optimalizační plugin', $html);
        assertContainsString('Optimalizace obrázků', $html);
        assertFalse(str_contains($html, 'WP Rocket'), 'WP Rocket nahradil optimalizační plugin a obrázky');
        assertContainsString("Zbývá 12 kroků · web spuštěn 13. 10. 2026", $html);
        assertContainsString('/weby/' . $id . '/sluzby" aria-current="page">Služby</a>', $html);
        assertFalse(str_contains($html, 'service--on'), 'Nový web nemá nic nasazeno');

        Urls::reset();
    },

    'služby: přepnutí služby se uloží, opakované zapnutí nevadí, vypnutí smaže' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $id = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);

        kernelRequest($kernel, 'POST', '/weby/' . $id . '/sluzby', ['_token' => $token, 'service' => ['gsc' => '1']]);
        kernelRequest($kernel, 'POST', '/weby/' . $id . '/sluzby', ['_token' => $token, 'service' => ['gsc' => '1']]);
        kernelRequest($kernel, 'POST', '/weby/' . $id . '/sluzby', ['_token' => $token, 'service' => ['ga' => '1']]);
        assertTrue($kernel->siteServices()->isEnabled($id, 'gsc'));
        $keys = array_keys($kernel->siteServices()->enabled($id));
        sort($keys);
        assertSame(['ga', 'gsc'], $keys);

        $html = kernelRequest($kernel, 'GET', '/weby/' . $id . '/sluzby')->body();
        assertContainsString('2 z 8 nasazeno', $html);
        assertContainsString('name="service[gsc]" value="0" role="checkbox" aria-checked="true"', $html);

        kernelRequest($kernel, 'POST', '/weby/' . $id . '/sluzby', ['_token' => $token, 'service' => ['gsc' => '0']]);
        assertFalse($kernel->siteServices()->isEnabled($id, 'gsc'));

        Urls::reset();
    },

    'služby: odškrtnutí úkolu podepíše účet a dnešek, JSON vrátí nové počty' => function (): void {
        [$kernel, $token] = loggedInKernel('Jan Novák');
        $id = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);

        $result = servicesJsonToggle($kernel, $id, ['_token' => $token, 'task' => ['website_auditor' => '1']]);

        assertSame(200, $result['status']);
        assertSame(true, $result['data']['item']['on']);
        assertSame('0', $result['data']['item']['next']);
        assertSame('Jan N. · ' . date('j. n. Y'), $result['data']['item']['meta']);
        assertSame(['text' => 'Hotovo', 'tone' => 'ok'], $result['data']['groups']['technika']);
        assertSame(['text' => '0 / 6', 'tone' => ''], $result['data']['groups']['seo']);
        assertSame('1 / 12', $result['data']['progress']['count']);
        assertSame(8, $result['data']['progress']['percent']);
        assertContainsString('datum spuštění webu doplňte v Nastavení', $result['data']['progress']['note']);

        $done = $kernel->siteServices()->done($id);
        assertSame('Jan Novák', $done['website_auditor']['user']['name']);

        $result = servicesJsonToggle($kernel, $id, ['_token' => $token, 'task' => ['website_auditor' => '0']]);
        assertSame('', $result['data']['item']['meta']);
        assertSame([], $kernel->siteServices()->done($id));

        Urls::reset();
    },

    'služby: všechno hotové → poznámka „Vše po spuštění je hotové."' => function (): void {
        [$kernel] = loggedInKernel();
        $id = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);

        foreach (SiteServices::keys(SiteServices::TASKS) as $key) {
            $kernel->siteServices()->setTask($id, $key, true, null);
        }

        $html = kernelRequest($kernel, 'GET', '/weby/' . $id . '/sluzby')->body();
        assertContainsString('Vše po spuštění je hotové.', $html);
        assertContainsString('--progress:100%', $html);
        assertFalse(str_contains($html, '<details class="launch-list__group" data-launch-group="seo" open'), 'Hotová skupina se nerozbaluje');

        Urls::reset();
    },

    'služby: neznámý klíč se odmítne, nic se neuloží' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $id = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);

        $result = servicesJsonToggle($kernel, $id, ['_token' => $token, 'service' => ['wp_rocket' => '1']]);

        assertSame(422, $result['status']);
        assertSame([], $kernel->siteServices()->enabled($id));

        Urls::reset();
    },

    'krátké jméno: „Jan Vašák" → „Jan V.", jedno slovo celé, bez jména login' => function (): void {
        assertSame('Jan V.', UserRepository::shortName(['name' => 'Jan Vašák', 'username' => 'jan']));
        assertSame('Petra K.', UserRepository::shortName(['name' => 'Petra Marie Kubíčková', 'username' => 'petra']));
        assertSame('Technik', UserRepository::shortName(['name' => 'Technik', 'username' => 'technik']));
        assertSame('technik', UserRepository::shortName(['name' => '', 'username' => 'technik']));
    },
];
