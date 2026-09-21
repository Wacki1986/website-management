<?php

declare(strict_types=1);

namespace App\Core\Db;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Tenká vrstva nad PDO.
 *
 * Připojení je líné — systémové endpointy (health) a chybové stránky se musí
 * vykreslit i tehdy, když databáze neodpovídá.
 *
 * Názvy tabulek a sloupců se do SQL vkládají jen přes quoteIdentifier(), který
 * odmítne cokoli mimo [a-z0-9_]. Hodnoty jdou vždy přes vázané parametry.
 */
final class Connection
{
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    /** @param array{host: string, port?: int, name: string, user: string, password: string, charset?: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'] ?? 3306,
            $this->config['name'],
            $this->config['charset'] ?? 'utf8mb4',
        );

        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                // Bez limitu čeká PDO na nedostupný server desítky sekund
                // a request mezitím drží spojení i PHP worker.
                PDO::ATTR_TIMEOUT => (int) ($this->config['timeout'] ?? 5),
            ]);
        } catch (PDOException $e) {
            // Zpráva PDO obsahuje přihlašovací údaje k DB — ven jde jen obecný text.
            $this->lastError = $e->getMessage();
            throw new RuntimeException('Nepodařilo se připojit k databázi.', 0, $e);
        }

        /**
         * PHP a databáze si musí rozumět v čase.
         *
         * Časy zapisuje PHP (`date('Y-m-d H:i:s')`), ale porovnává je SQL proti
         * `NOW()`. Když má databáze jinou zónu než PHP, rozdíl se promítne do
         * všeho, co se počítá z časů — u docházky do odpracovaných hodin.
         * Nález z provozu: lokální PHP běželo v UTC, MariaDB na systémovém
         * čase (Europe/Budapest), a běžící stopky hlásily o dvě hodiny víc.
         *
         * Posun se posílá číselně (`+02:00`), ne názvem zóny — pojmenované zóny
         * vyžadují naplněné tabulky `mysql.time_zone*`, které na sdílených
         * hostinzích většinou prázdné jsou.
         */
        $this->pdo->exec('SET time_zone = ' . $this->pdo->quote(date('P')));

        return $this->pdo;
    }

    public function isAvailable(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        $this->execute($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new InvalidArgumentException('UPDATE musí mít data i podmínku.');
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        [$whereSql, $whereParams] = $this->buildWhere($where);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $set),
            $whereSql,
        );

        return $this->execute($sql, array_merge($params, $whereParams));
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new InvalidArgumentException('DELETE bez podmínky není povolen.');
        }

        [$whereSql, $params] = $this->buildWhere($where);

        return $this->execute(
            sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $whereSql),
            $params,
        );
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Nepovolený název "%s" v SQL.', $identifier));
        }

        return '`' . $identifier . '`';
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $where): array
    {
        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = $this->quoteIdentifier($column) . ' IS NULL';
                continue;
            }

            $conditions[] = $this->quoteIdentifier($column) . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        return [implode(' AND ', $conditions), $params];
    }
}
