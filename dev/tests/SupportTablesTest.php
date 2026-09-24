<?php

declare(strict_types=1);

/**
 * Konce podpory PHP a databází z endoflife.date: čtení odpovědi,
 * rozdíly proti používané tabulce, uložení a použití, výpadek služby.
 * Stahování je podvržené — testy nesahají na internet.
 */

use App\Core\Monitor\DbSupport;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\SupportTables;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/** @return callable(string): ?string odpovědi endoflife.date podle produktu; null = výpadek */
function stFetcher(array $answers): callable
{
    return static function (string $url) use ($answers): ?string {
        foreach ($answers as $product => $json) {
            if (str_contains($url, '/' . $product . '.json')) {
                return $json;
            }
        }

        return null;
    };
}

const ST_PHP = '[{"cycle":"8.4","eol":"2029-12-31"},{"cycle":"8.3","eol":"2028-12-31"},{"cycle":"8.2","eol":"2026-12-31"},{"cycle":"7.4","eol":true}]';
const ST_MARIADB = '[{"cycle":"12.0","eol":false},{"cycle":"11.8","eol":"2028-06-04"},{"cycle":"10.6","eol":"2026-07-06"}]';
const ST_MYSQL = '{"result":{"releases":[{"name":"8.4","eolFrom":"2032-04-30","isEol":false},{"name":"8.0","eolFrom":"2026-04-30","isEol":true}]}}';

return [
    'čtení: seznam cyklů i formát v1, neoznámený a neuvedený konec, řazení podle verze' => function (): void {
        assertSame(['7.4' => '2000-01-01', '8.2' => '2026-12-31', '8.3' => '2028-12-31', '8.4' => '2029-12-31'], SupportTables::parse(ST_PHP));
        assertSame(['10.6' => '2026-07-06', '11.8' => '2028-06-04', '12.0' => '9999-12-31'], SupportTables::parse(ST_MARIADB));
        assertSame(['8.0' => '2026-04-30', '8.4' => '2032-04-30'], SupportTables::parse(ST_MYSQL));
        assertSame([], SupportTables::parse('<html>chyba</html>'));
    },

    'ověření: uloží, použije a vypíše změny proti vestavěné tabulce' => function (): void {
        [$kernel] = loggedInKernel('Správce');

        try {
            $tables = new SupportTables($kernel->settings(), stFetcher(['php' => ST_PHP, 'mysql' => ST_MYSQL, 'mariadb' => ST_MARIADB]));
            assertTrue($tables->isDue());

            $result = $tables->refresh(strtotime('2026-09-24 12:00:00'));
            assertTrue($result['ok']);
            assertContainsString('PHP 8.3: konec podpory 31. 12. 2027 → 31. 12. 2028', implode("\n", $result['changes']));
            assertContainsString('MariaDB 12.0: nově v tabulce, konec podpory zatím neoznámen', implode("\n", $result['changes']));

            // Použité tabulky: PHP 8.3 podle stažených dat, MariaDB 12.0 podporovaná.
            assertSame('2028-12-31', PhpSupport::endOfLife('8.3.20'));
            assertSame('', DbSupport::tone('MariaDB', '12.0.2', strtotime('2026-09-24')));
            assertSame('error', PhpSupport::tone('7.4.33'));
            assertFalse($tables->isDue(strtotime('2026-10-10')));
            assertTrue($tables->isDue(strtotime('2026-10-30')));
        } finally {
            PhpSupport::useTable(null);
            DbSupport::useTables(null);
        }
    },

    'výpadek: co se nestáhlo, zůstává z minula; bez dat platí vestavěná tabulka' => function (): void {
        [$kernel] = loggedInKernel('Správce');

        try {
            (new SupportTables($kernel->settings(), stFetcher(['php' => ST_PHP, 'mysql' => ST_MYSQL, 'mariadb' => ST_MARIADB])))->refresh();
            $result = (new SupportTables($kernel->settings(), stFetcher(['php' => ST_PHP])))->refresh();

            assertFalse($result['ok']);
            assertContainsString('mysql, mariadb', (string) $result['error']);
            assertSame('2028-06-04', DbSupport::endOfLife('MariaDB', '11.8.1'), 'MariaDB zůstala z minulého ověření');
            assertSame('9999-12-31', DbSupport::endOfLife('MariaDB', '12.0.1'));

            PhpSupport::useTable(null);
            DbSupport::useTables(null);
            assertSame(PhpSupport::TABLE['8.3'], PhpSupport::endOfLife('8.3.1'), 'Bez uložených dat vestavěná tabulka');
        } finally {
            PhpSupport::useTable(null);
            DbSupport::useTables(null);
        }
    },

    'nastavení: karta s verzemi webů a tlačítko Ověřit teď' => function (): void {
        [$kernel] = loggedInKernel('Správce');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $kernel->db()->execute("INSERT INTO site_snapshots (site_id, fetched_at, payload, php_version, wp_version, db_type, db_version, plugins_total, plugins_active, plugins_updates) VALUES (:id, NOW(), '{}', '8.2.28', '6.9', 'MariaDB', '10.6.21', 10, 10, 0)", ['id' => $siteId]);

        try {
            $html = kernelRequest($kernel, 'GET', '/nastaveni/monitoring')->body();
            assertContainsString('Konce podpory PHP a databází', $html);
            assertContainsString('zatím nikdy', $html);
            assertContainsString('PHP 8.2', $html);
            assertContainsString('bez podpory · konec 6. 7. 2026', $html);
            assertContainsString('action="/nastaveni/monitoring/verze"', $html);
        } finally {
            PhpSupport::useTable(null);
            DbSupport::useTables(null);
            Urls::reset();
        }
    },
];
