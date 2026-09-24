<?php

declare(strict_types=1);

/**
 * Podpora verzí PHP a databáze: barvy ve výpisu webů (červeně bez
 * podpory, oranžově do roka konec) a předvyplněná poznámka servisu.
 */

use App\Core\Monitor\DbSupport;
use App\Core\Monitor\PhpSupport;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'PHP: bez podpory červeně, do roka konec oranžově, jinak nic' => function (): void {
        $now = strtotime('2026-09-24 12:00:00');

        assertSame('error', PhpSupport::tone('8.1.30', $now));
        assertSame('warning', PhpSupport::tone('8.2.28', $now), 'PHP 8.2 končí 31. 12. 2026');
        assertSame('', PhpSupport::tone('8.3.20', $now));
        assertSame('', PhpSupport::tone('9.0.0', $now), 'Novější verze, než tabulka zná');
        assertSame('', PhpSupport::tone('', $now));

        assertContainsString('31. 12. 2026', (string) PhpSupport::advice('8.2.28', $now));
        assertContainsString('PHP ' . PhpSupport::RECOMMENDED, (string) PhpSupport::advice('7.4.33', $now));
        assertSame(null, PhpSupport::advice('8.4.1', $now));
    },

    'databáze: LTS podle tabulky, krátkodobé verze starší než nejnovější LTS bez podpory' => function (): void {
        $now = strtotime('2026-09-24 12:00:00');

        assertSame('error', DbSupport::tone('MariaDB', '10.6.21', $now), 'MariaDB 10.6 skončila 6. 7. 2026');
        assertSame('', DbSupport::tone('MariaDB', '10.11.11', $now));
        assertSame('error', DbSupport::tone('MariaDB', '10.9.2', $now), 'Krátkodobá verze');
        assertSame('', DbSupport::tone('MariaDB', '12.0.1', $now), 'Novější než tabulka');
        assertSame('error', DbSupport::tone('MySQL', '8.0.41', $now));
        assertSame('error', DbSupport::tone('MySQL', '8.2.0', $now), 'Innovation release');
        assertSame('', DbSupport::tone('MySQL', '8.4.4', $now));
        assertSame('warning', DbSupport::tone('MariaDB', '10.11.11', strtotime('2027-06-01')), 'Rok před koncem 10.11');
        assertSame('', DbSupport::tone('', '', $now));
        assertSame('MariaDB 10.11', DbSupport::label('MariaDB', '10.11.11'));
        assertContainsString('MariaDB 11.4', (string) DbSupport::advice('MariaDB', '10.6.21', $now));
    },

    'výpis webů: WP, PHP a databáze ve vlastních sloupcích s barvou; servis předvyplní poznámku' => function (): void {
        [$kernel] = loggedInKernel('Technik');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $kernel->db()->execute("INSERT INTO site_snapshots (site_id, fetched_at, payload, php_version, wp_version, db_type, db_version, plugins_total, plugins_active, plugins_updates) VALUES (:id, NOW(), '{}', '8.2.28', '6.9', 'MariaDB', '10.6.21', 10, 10, 0)", ['id' => $siteId]);

        $html = kernelRequest($kernel, 'GET', '/weby')->body();
        assertContainsString('<div>WP</div><div>PHP</div><div>Databáze</div>', $html);
        assertContainsString('text-warning" title="PHP 8.2 přestane', $html);
        assertContainsString('text-error" title="Databáze MariaDB 10.6 už nedostává', $html);
        assertContainsString('>MariaDB 10.6</div>', $html);

        $form = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/zapsat')->body();
        assertContainsString('PHP 8.2 přestane', $form);
        assertContainsString('Databáze MariaDB 10.6 už nedostává bezpečnostní opravy', $form);

        Urls::reset();
    },
];
