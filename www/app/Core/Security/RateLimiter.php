<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Db\Connection;

/**
 * Omezení počtu pokusů — bez Redisu, stačí tabulka a index.
 *
 * Původně to uměla jen `Auth\LoginRateLimiter` a bylo to natvrdo svázané
 * s přihlašováním. Jenže veřejný endpoint pro poptávky potřebuje totéž
 * a kopírovat kvůli tomu celou třídu by znamenalo dvě místa, kde se musí
 * opravit každá chyba.
 *
 * ## Kbelík a klíč
 *
 * Pokus se zapisuje do **kbelíku** (`login`, `enquiry`, …) pod nějakým
 * **klíčem** — přihlašovacím jménem, IP adresou, čímkoli. Limit se pak ptá
 * „kolik pokusů má tenhle klíč v tomhle kbelíku za poslední okno". Dvojí
 * ochrana (na účet i na IP) je proto jen dvojí dotaz, ne zvláštní kód.
 *
 * ## Proč se počítají jen neúspěchy
 *
 * U přihlášení dává smysl blokovat po pěti špatných heslech, ne po pěti
 * přihlášeních. Zápis proto nese příznak úspěchu a limit se dívá jen na to,
 * co selhalo — až na kbelíky, kde je „úspěch" samo o sobě to, co se má
 * omezovat (poptávky). Tam se zapisuje jako neúspěch, protože počítadlo
 * nezná rozdíl mezi „nepovedlo se" a „nemá se opakovat".
 */
final class RateLimiter
{
    public const TABLE = 'rate_limits';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Kolik pokusů má klíč v kbelíku za poslední okno?
     *
     * @param int $windowMinutes délka okna; počítá se od teď dozadu, ne od
     *                           celé hodiny — jinak by šlo limit obejít
     *                           načasováním na přelom
     */
    public function count(string $bucket, string $key, int $windowMinutes): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . '
             WHERE bucket = :bucket AND `key` = :key AND success = 0 AND attempted_at >= :since',
            [
                'bucket' => $bucket,
                'key' => $key,
                'since' => date('Y-m-d H:i:s', time() - ($windowMinutes * 60)),
            ],
        );
    }

    public function tooMany(string $bucket, string $key, int $max, int $windowMinutes): bool
    {
        return $this->count($bucket, $key, $windowMinutes) >= $max;
    }

    public function record(string $bucket, string $key, bool $success = false): void
    {
        $this->db->insert(self::TABLE, [
            'bucket' => mb_substr($bucket, 0, 40),
            // Klíč se ořezává, ne hashuje: u přihlášení je to jméno, které
            // musí jít přečíst při vyšetřování „proč se nemůžu přihlásit".
            'key' => mb_substr($key, 0, 191),
            'success' => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Po úspěchu se počítadlo pro daný klíč vynuluje. */
    public function clear(string $bucket, string $key): void
    {
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE bucket = :bucket AND `key` = :key AND success = 0',
            ['bucket' => $bucket, 'key' => $key],
        );
    }

    /**
     * Úklid z cronu.
     *
     * Starší pokusy nemají žádnou hodnotu a jsou v nich IP adresy —
     * uchovávat je déle by byl zbytečný osobní údaj (GDPR minimalizace).
     */
    public function purge(int $days = 30): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE attempted_at < :before',
            ['before' => date('Y-m-d H:i:s', time() - ($days * 86400))],
        );
    }
}
