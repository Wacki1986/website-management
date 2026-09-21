<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Db\Connection;

/**
 * „Historie změn" u webu — všechno, co se s webem stalo: výpadek, změna
 * verze pluginu, odeslaný report, zapsaný servis. Zapisuje ji i automatika
 * (monitor), proto je oddělená od auditního logu, který drží jen zásahy
 * lidí.
 *
 * `detail` je JSON pro report (co se aktualizovalo z jaké na jakou verzi);
 * `message` je hotová věta pro výpis.
 */
final class EventLog
{
    public const KIND_UPTIME = 'uptime';
    public const KIND_ALERT = 'alert';
    public const KIND_PLUGIN = 'plugin';
    public const KIND_CORE = 'core';
    public const KIND_REPORT = 'report';
    public const KIND_SERVICE = 'service';
    public const KIND_SYSTEM = 'system';
    public const KIND_SECURITY = 'security';
    public const KIND_SETTINGS = 'settings';
    public const KIND_CLIENT = 'client';

    /** @var array<string, string> kód => popisek do sloupce v tabulce */
    public const KINDS = [
        self::KIND_UPTIME => 'uptime',
        self::KIND_ALERT => 'alert',
        self::KIND_PLUGIN => 'plugin',
        self::KIND_CORE => 'WordPress',
        self::KIND_REPORT => 'report',
        self::KIND_SERVICE => 'servis',
        self::KIND_SYSTEM => 'systém',
        self::KIND_SECURITY => 'zabezpečení',
        self::KIND_SETTINGS => 'nastavení',
        self::KIND_CLIENT => 'klient',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string $tone ok | warning | error
     * @param array<string, mixed> $detail strukturovaný detail pro report
     */
    public function record(int $siteId, string $kind, string $tone, string $message, array $detail = [], string $userName = ''): int
    {
        return $this->db->insert('events', [
            'site_id' => $siteId,
            'kind' => $kind,
            'tone' => in_array($tone, ['ok', 'warning', 'error'], true) ? $tone : 'ok',
            'message' => mb_substr($message, 0, 500),
            'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'user_name' => mb_substr($userName, 0, 100),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function latest(int $siteId, int $limit = 6): array
    {
        return $this->db->select(
            'SELECT * FROM events WHERE site_id = :site_id ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['site_id' => $siteId],
        );
    }

    public function count(int $siteId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM events WHERE site_id = :site_id', ['site_id' => $siteId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function page(int $siteId, int $limit, int $offset): array
    {
        return $this->db->select(
            'SELECT * FROM events WHERE site_id = :site_id ORDER BY created_at DESC, id DESC LIMIT '
                . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['site_id' => $siteId],
        );
    }

    /**
     * Události daného druhu v období — zdroj pro klientský report
     * („aktualizovali jsme 11 doplňků").
     *
     * @param array<int, string> $kinds
     * @return array<int, array<string, mixed>>
     */
    public function between(int $siteId, string $from, string $to, array $kinds = []): array
    {
        $params = ['site_id' => $siteId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        $kindSql = '';

        if ($kinds !== []) {
            $placeholders = [];

            foreach (array_values($kinds) as $i => $kind) {
                $placeholders[] = ':kind' . $i;
                $params['kind' . $i] = $kind;
            }

            $kindSql = ' AND kind IN (' . implode(', ', $placeholders) . ')';
        }

        return $this->db->select(
            'SELECT * FROM events WHERE site_id = :site_id AND created_at BETWEEN :from AND :to' . $kindSql
                . ' ORDER BY created_at',
            $params,
        );
    }

    /** Poslední události napříč weby (dashboard, detail klienta). @return array<int, array<string, mixed>> */
    public function recent(int $limit = 10, ?array $siteIds = null): array
    {
        $where = '';
        $params = [];

        if ($siteIds !== null) {
            if ($siteIds === []) {
                return [];
            }

            $where = ' WHERE e.site_id IN (' . implode(', ', array_map('intval', $siteIds)) . ')';
        }

        return $this->db->select(
            'SELECT e.*, s.name AS site_name FROM events e JOIN sites s ON s.id = e.site_id' . $where
                . ' ORDER BY e.created_at DESC, e.id DESC LIMIT ' . max(1, $limit),
            $params,
        );
    }

    /** Mazání starší historie (retence) — kromě ní se z událostí nic nemaže. */
    public function purgeOlderThan(int $months): int
    {
        return $this->db->execute(
            'DELETE FROM events WHERE created_at < :before',
            ['before' => date('Y-m-d H:i:s', strtotime('-' . max(1, $months) . ' months'))],
        );
    }
}
