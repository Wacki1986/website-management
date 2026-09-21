<?php

declare(strict_types=1);

namespace App\Core\Db;

use PDO;
use Throwable;

/**
 * Migrace bez SSH.
 *
 * Migrační soubor vrací closure `function (PDO $pdo): void`. Název souboru
 * (timestamp prefix) určuje pořadí a zároveň slouží jako identifikátor v tabulce
 * `migrations`.
 *
 * MySQL nezná transakční DDL, takže platí pravidlo: jedna migrace = jeden malý
 * idempotentní krok (CREATE TABLE IF NOT EXISTS, kontrola sloupce před ALTER).
 * Když migrace spadne v půlce, musí jít bezpečně spustit znovu.
 *
 * Migrace jen PŘIBÝVAJÍ a schéma se nikdy nemění mimo ně. Nová instalace
 * schválně projede úplně stejnou řadu migrací jako povýšení existující
 * instance — `pending()` je rozdíl `discover()` mínus `applied()`, takže obojí
 * jde jednou cestou kódu a skončí u bajtově stejného schématu. Vlastní zkratka
 * pro nové instalace (rovnou hotové schéma) by znamenala druhou definici téhož
 * a jednoho dne tichý rozdíl mezi nově založenou a povýšenou instancí; desítky
 * příkazů na prázdné databázi za to nestojí.
 *
 * Squash do baseline je jednorázová hygiena, ne rutina, a smí se jen tehdy,
 * když jsou všechny živé instance na verzi squashe nebo výš — instanci na starší
 * verzi by chyběl mezikrok a už by se nikdy nezmigrovala. Naposledy se squashovalo
 * do verze 0.9.0.
 */
final class Migrator
{
    /** @var array<string, string> modul => adresář s migracemi */
    private array $paths = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function addPath(string $module, string $directory): void
    {
        if (is_dir($directory)) {
            $this->paths[$module] = rtrim($directory, '/\\');
        }
    }

    /** @return array<int, array{module: string, name: string, file: string}> */
    public function discover(): array
    {
        $found = [];

        foreach ($this->paths as $module => $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $found[] = [
                    'module' => $module,
                    'name' => basename($file, '.php'),
                    'file' => $file,
                ];
            }
        }

        // Jádro první (moduly na jeho tabulky odkazují), pak podle timestampu v názvu.
        usort($found, static function (array $a, array $b): int {
            $coreFirst = ($a['module'] === 'core' ? 0 : 1) <=> ($b['module'] === 'core' ? 0 : 1);

            return $coreFirst !== 0 ? $coreFirst : strcmp($a['name'], $b['name']);
        });

        return $found;
    }

    /** @return array<int, string> klíče "modul:název" */
    public function applied(): array
    {
        $this->ensureMigrationsTable();

        $rows = $this->db->select('SELECT module, name FROM migrations');

        return array_map(
            static fn (array $row): string => $row['module'] . ':' . $row['name'],
            $rows,
        );
    }

    /** @return array<int, array{module: string, name: string, file: string}> */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->discover(),
            static fn (array $m): bool => !in_array($m['module'] . ':' . $m['name'], $applied, true),
        ));
    }

    /**
     * Levná kontrola pro bootstrap — nesmí sahat na soubory migrací víc než musí.
     *
     * Porovnávají se MNOŽINY, ne počty. Počty by stačily jen tehdy, kdyby soubor
     * migrace nikdy nezmizel — jenže při squashi na baseline se staré soubory
     * mažou a v tabulce `migrations` po nich zůstanou osiřelé řádky. Nafouknutý
     * počet by pak natrvalo přebil počet nalezených souborů a tahle metoda by
     * hlásila „nic nečeká" i s reálně čekající migrací — tedy tichá ztráta brány
     * údržby v `Kernel::handle()`.
     *
     * Tabulka se tu záměrně NEZAKLÁDÁ (na rozdíl od `pending()`, které jde přes
     * `applied()`): tohle běží při každém požadavku a DDL na čtecí cestu nepatří.
     */
    public function hasPending(): bool
    {
        try {
            $applied = $this->db->select('SELECT module, name FROM migrations');
        } catch (Throwable $e) {
            // Nedostupná databáze není totéž co nezmigrované schéma — jinak by
            // výpadek spojení vypadal jako probíhající aktualizace.
            if (!$this->db->isAvailable()) {
                throw $e;
            }

            return true; // tabulka migrations ještě neexistuje
        }

        $keys = [];

        foreach ($applied as $row) {
            $keys[$row['module'] . ':' . $row['name']] = true;
        }

        foreach ($this->discover() as $migration) {
            if (!isset($keys[$migration['module'] . ':' . $migration['name']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{applied: array<int, string>, failed: ?string, message: string}
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();

        $applied = [];
        $pending = $this->pending();

        foreach ($pending as $migration) {
            $callback = require $migration['file'];

            if (!is_callable($callback)) {
                return [
                    'applied' => $applied,
                    'failed' => $migration['name'],
                    'message' => sprintf('Migrace %s nevrací callable.', $migration['name']),
                ];
            }

            try {
                $callback($this->db->pdo());
            } catch (Throwable $e) {
                return [
                    'applied' => $applied,
                    'failed' => $migration['name'],
                    'message' => sprintf('Migrace %s selhala: %s', $migration['name'], $e->getMessage()),
                ];
            }

            $this->db->insert('migrations', [
                'module' => $migration['module'],
                'name' => $migration['name'],
                'applied_at' => date('Y-m-d H:i:s'),
            ]);

            $applied[] = $migration['module'] . ':' . $migration['name'];
        }

        return [
            'applied' => $applied,
            'failed' => null,
            'message' => $applied === []
                ? 'Vše je aktuální, nebylo co migrovat.'
                : sprintf('Aplikováno migrací: %d.', count($applied)),
        ];
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                module VARCHAR(50) NOT NULL,
                name VARCHAR(191) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migration (module, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci'
        );
    }

    /** Pomůcka pro migrace: existuje sloupec? (kvůli idempotenci ALTERů) */
    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Pomůcka pro migrace: existuje index? */
    public static function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Pomůcka pro migrace: existuje cizí klíč nebo jiné omezení? */
    public static function hasConstraint(PDO $pdo, string $table, string $constraint): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $statement->execute([$table, $constraint]);

        return (int) $statement->fetchColumn() > 0;
    }
}
