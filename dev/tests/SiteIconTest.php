<?php

declare(strict_types=1);

/**
 * Ikona webu: výběr z `<head>` stránky, stažení favicony z falešného webu,
 * přeskočení výchozího loga WordPressu, výdej routou a návrat k iniciálám.
 */

use App\Core\Sites\SiteIcons;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/FakeInstance.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/** @param callable(string): void $test dostane adresu falešného webu */
function withIconSite(int $port, string $mode, callable $test): void
{
    if (!FakeInstance::start($port, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_ICON' => $mode], 'fake-wp-site.php')) {
        skip('falešný web nenastartoval: ' . FakeInstance::lastError());
    }

    try {
        $test('http://127.0.0.1:' . $port);
    } finally {
        FakeInstance::stop();
    }
}

return [
    'odkazy na ikony z hlavičky: větší první, SVG a data: pryč, relativní adresy doplněné' => function (): void {
        $html = '<head>'
            . '<link rel="icon" href="/ikona-32.png" sizes="32x32">'
            . "<link rel='shortcut icon' href='favicon.png'>"
            . '<link rel="icon" type="image/svg+xml" href="/logo.svg">'
            . '<link rel="icon" href="data:image/png;base64,AAAA">'
            . '<link rel="apple-touch-icon" href="//cdn.web.cz/apple.png">'
            . '<link rel="stylesheet" href="/styl.css">'
            . '</head>';

        assertSame([
            'https://cdn.web.cz/apple.png',
            'https://web.cz/ikona-32.png',
            'https://web.cz/blog/favicon.png',
        ], SiteIcons::candidates($html, 'https://web.cz/blog/'));

        assertSame('https://web.cz/a.png', SiteIcons::resolve('/a.png', 'https://web.cz/x/y'));
        assertSame(null, SiteIcons::resolve('javascript:alert(1)', 'https://web.cz/'));
        assertSame(null, SiteIcons::mimeOfBytes('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    },

    'favicona z hlavičky stránky se stáhne, uloží a vydá routou' => function (): void {
        withIconSite(8251, 'link', function (string $url): void {
            [$kernel, $token] = loggedInKernel('Technik', ['allow_insecure_sites' => true]);
            $id = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $url]);

            assertTrue($kernel->siteIcons()->refresh($kernel->sites()->find($id)));
            $site = $kernel->sites()->find($id);
            assertSame(SiteIcons::SOURCE_AUTO, $site['icon_source']);
            assertTrue($site['icon_checked_at'] !== null);
            assertTrue(is_file($kernel->siteIcons()->absolutePath((string) $site['icon'])));

            $response = kernelRequest($kernel, 'GET', '/weby/' . $id . '/ikona');
            assertSame(200, $response->status());
            assertSame('image/png', $response->headers()['Content-Type'] ?? '');

            assertContainsString('src="/weby/' . $id . '/ikona?v=', kernelRequest($kernel, 'GET', '/weby')->body());

            // Odebrání: zpátky k iniciálám, soubor pryč, automatika to zkusí znovu.
            kernelRequest($kernel, 'POST', '/weby/' . $id . '/ikona/smazat', ['_token' => $token]);
            $removed = $kernel->sites()->find($id);
            assertSame('', $removed['icon']);
            assertSame(null, $removed['icon_checked_at']);
            assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $id . '/ikona')->status());

            Urls::reset();
        });
    },

    'bez odkazů v hlavičce se vezme /favicon.ico (i ve formátu ICO)' => function (): void {
        withIconSite(8252, 'favicon', function (string $url): void {
            [$kernel] = loggedInKernel('Technik', ['allow_insecure_sites' => true]);
            $id = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $url]);

            assertTrue($kernel->siteIcons()->refresh($kernel->sites()->find($id)));
            assertTrue(str_ends_with((string) $kernel->sites()->find($id)['icon'], '.ico'));

            Urls::reset();
        });
    },

    'výchozí logo WordPressu se nebere — web bez ikony zůstane u iniciál' => function (): void {
        withIconSite(8253, 'wplogo', function (string $url): void {
            [$kernel] = loggedInKernel('Technik', ['allow_insecure_sites' => true]);
            $id = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $url]);

            assertFalse($kernel->siteIcons()->refresh($kernel->sites()->find($id)));
            $site = $kernel->sites()->find($id);
            assertSame('', $site['icon']);
            assertTrue($site['icon_checked_at'] !== null, 'Pokus se zapíše, ať se web nezkouší při každém běhu');

            // Krok cronu: web zkontrolovaný před chvílí se znovu nezkouší.
            assertSame(0, $kernel->siteIcons()->refreshStale(time(), microtime(true) + 60));

            Urls::reset();
        });
    },
];
