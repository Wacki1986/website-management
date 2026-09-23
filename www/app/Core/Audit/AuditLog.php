<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Auth\Auth;
use App\Core\Auth\UserRepository;
use App\Core\Db\Connection;

/**
 * Auditní log zásahů.
 *
 * Zapisuje se všechno, co člověk v aplikaci **změní** — u webu, u klienta,
 * u účtů. Je to jediný důkaz při sporu s klientem („kdo vypnul hlídání?",
 * „kdo odeslal report?"). Slovník akcí je uzavřený a rozšiřuje se tady.
 *
 * Autor zásahu se zjišťuje sám z `Auth::current()` — volající místa
 * nemusí uživatele nikam protahovat. Jméno i název webu se ukládají jako
 * snapshot: záznam musí přežít i zrušení účtu a smazání webu.
 *
 * Od „Historie změn" u webu (`Core\Events\EventLog`) se liší účelem: tam
 * jde o všechno, co se s webem stalo (i automaticky), tady jen o to, co
 * udělal člověk. Nikdy se nemaže.
 */
final class AuditLog
{
    public const ACTION_SITE_ADD = 'web-pridan';
    public const ACTION_SITE_EDIT = 'web-upraven';
    public const ACTION_SITE_REMOVE = 'web-odebran';
    public const ACTION_SITE_KEY = 'web-klic';
    public const ACTION_PLUGIN_UPDATE = 'pluginy-aktualizace';
    public const ACTION_PLUGIN_DELETE = 'plugin-smazani';
    public const ACTION_CORE_UPDATE = 'wordpress-aktualizace';
    public const ACTION_CLIENT = 'klient';
    public const ACTION_ALERT = 'alert';
    public const ACTION_SERVICE = 'servis';
    public const ACTION_REPORT = 'report';
    public const ACTION_USER = 'uzivatel';
    public const ACTION_SETTINGS = 'nastaveni';

    /**
     * Popisek a tón odznaku ke každé akci — jediný slovník pro výpis
     * i nabídku filtru.
     *
     * @var array<string, array{0: string, 1: string}> kód => [popisek, tón odznaku]
     */
    public const ACTIONS = [
        self::ACTION_SITE_ADD => ['Přidání webu', 'brand'],
        self::ACTION_SITE_EDIT => ['Úprava webu', ''],
        self::ACTION_SITE_REMOVE => ['Odebrání webu', 'error'],
        self::ACTION_SITE_KEY => ['Nový API klíč', 'warning'],
        self::ACTION_PLUGIN_UPDATE => ['Aktualizace pluginů', 'brand'],
        self::ACTION_PLUGIN_DELETE => ['Smazání pluginu', 'error'],
        self::ACTION_CORE_UPDATE => ['Aktualizace WordPressu', 'brand'],
        self::ACTION_CLIENT => ['Klient', ''],
        self::ACTION_ALERT => ['Alert', 'warning'],
        self::ACTION_SERVICE => ['Servis', 'brand'],
        self::ACTION_REPORT => ['Report', 'brand'],
        self::ACTION_USER => ['Uživatel', ''],
        self::ACTION_SETTINGS => ['Nastavení', ''],
    ];

    /** @return array{0: string, 1: string} [popisek, tón] — neznámý kód se ukáže tak, jak je */
    public static function badge(string $action): array
    {
        return self::ACTIONS[$action] ?? [$action, ''];
    }

    public function __construct(
        private readonly Connection $db,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $detail technický detail (co se změnilo, chyba…) */
    public function record(
        ?int $siteId,
        string $siteName,
        string $action,
        bool $success,
        string $description,
        array $detail = [],
    ): void {
        $actor = $this->auth->current();

        $this->db->insert('audit_log', [
            'site_id' => $siteId,
            'site_name' => mb_substr($siteName, 0, 150),
            'action' => $action,
            'user_id' => $actor !== null ? (int) $actor['id'] : null,
            'user_name' => $actor !== null ? mb_substr(UserRepository::displayName($actor), 0, 100) : '',
            'success' => $success ? 1 : 0,
            'description' => mb_substr($description, 0, 500),
            'detail' => $detail === []
                ? null
                : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Poslední záznamy, volitelně jen jednoho webu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(?int $siteId = null, int $limit = 200): array
    {
        $where = $siteId !== null ? ' WHERE site_id = :site_id' : '';
        $params = $siteId !== null ? ['site_id' => $siteId] : [];

        return $this->db->select(
            'SELECT * FROM audit_log' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit)),
            $params,
        );
    }
}
