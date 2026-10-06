<?php

declare(strict_types=1);

namespace App\Core\Projects;

use App\Core\Db\Connection;
use App\Core\Monitor\DomainChecker;

/**
 * Služby projektu — domény a hosting (tabulka `project_services`): u koho
 * jsou, kdy se obnovují a co z toho fakturujeme klientovi.
 *
 * Stavy pro obrazovky (obnova, fakturace, cena) počítají statické metody
 * bez databáze — testují se samy a šablona dostane hotový popisek s tónem
 * (tečka + text, `get_status()`).
 *
 * Fakturace: `renews_on` je konec zaplaceného období, `invoiced_until`
 * do kdy má klient vyfakturováno. Když se obnova blíží (`$days` z pravidla
 * „Obnova služeb") a příští období ještě vyfakturované není, služba čeká
 * na fakturu. „Vyfakturováno" posune `invoiced_until` na konec příštího
 * období, „Obnoveno" posune `renews_on` o periodu.
 */
final class ProjectServices
{
    public const KIND_DOMAIN = 'domain';
    public const KIND_HOSTING = 'hosting';

    /** @var array<string, array{label: string, icon: string, slug: string}> druh => popisek, ikona, část adresy */
    public const KINDS = [
        self::KIND_DOMAIN => ['label' => 'Doména', 'icon' => 'globe', 'slug' => 'domena'],
        self::KIND_HOSTING => ['label' => 'Hosting', 'icon' => 'server', 'slug' => 'hosting'],
    ];

    /** @var array<int, string> měsíce => popisek periody */
    public const PERIODS = [1 => 'měsíčně', 3 => 'čtvrtletně', 12 => 'ročně', 24 => 'na 2 roky', 36 => 'na 3 roky'];

    /** @var array<int, string> měsíce => „/ rok" za cenou */
    private const PERIOD_SUFFIX = [1 => 'měsíc', 3 => 'čtvrtletí', 12 => 'rok', 24 => '2 roky', 36 => '3 roky'];

    public const CURRENCIES = ['CZK' => 'Kč', 'EUR' => '€'];

    public const PAID_BY_US = 'us';
    public const PAID_BY_CLIENT = 'client';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Druh z části adresy (`domena` → `domain`), neznámý → null. */
    public static function kindFromSlug(string $slug): ?string
    {
        foreach (self::KINDS as $kind => $meta) {
            if ($meta['slug'] === $slug) {
                return $kind;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM project_services WHERE id = :id', ['id' => $id]);
    }

    /** Služby projektu — domény první, pak podle obnovy. @return array<int, array<string, mixed>> */
    public function forProject(int $projectId): array
    {
        return $this->db->select(
            "SELECT * FROM project_services WHERE project_id = :id
             ORDER BY FIELD(kind, 'domain', 'hosting'), renews_on IS NULL, renews_on, name",
            ['id' => $projectId],
        );
    }

    /**
     * Všechny služby s projektem a klientem — přehled Obnovy a fakturace.
     * Seřazeno podle obnovy, bez data na konci.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT ps.*, p.name AS project_name, p.client_id, c.name AS client_name
             FROM project_services ps
             JOIN projects p ON p.id = ps.project_id
             LEFT JOIN clients c ON c.id = p.client_id
             ORDER BY ps.renews_on IS NULL, ps.renews_on, p.name',
        );
    }

    /** @param array<string, mixed> $data */
    public function create(int $projectId, string $kind, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('project_services', $data + ['project_id' => $projectId, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('project_services', $data + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('project_services', ['id' => $id]);
    }

    /** Klient zaplatil příští období: vyfakturováno do konce období po obnově. */
    public function markInvoiced(int $id, ?string $today = null): void
    {
        $service = $this->find($id);

        if ($service !== null) {
            $base = (string) ($service['renews_on'] ?? '') !== '' ? (string) $service['renews_on'] : ($today ?? date('Y-m-d'));
            $this->update($id, ['invoiced_until' => self::addMonths($base, (int) $service['period_months'])]);
        }
    }

    /** Služba je u poskytovatele zaplacená na další období. */
    public function markRenewed(int $id, ?string $today = null): void
    {
        $service = $this->find($id);

        if ($service !== null) {
            $base = (string) ($service['renews_on'] ?? '') !== '' ? (string) $service['renews_on'] : ($today ?? date('Y-m-d'));
            $this->update($id, ['renews_on' => self::addMonths($base, (int) $service['period_months']), 'notified_for' => null]);
        }
    }

    /**
     * Služby, jejichž obnova je do `$days` dní (nebo už proběhla) a ještě
     * o ní nešlo upozornění — jednou na každé `renews_on`.
     *
     * @return array<int, array<string, mixed>> s `project_name` a `client_name`
     */
    public function dueForNotice(string $today, int $days): array
    {
        return $this->db->select(
            'SELECT ps.*, p.name AS project_name, c.name AS client_name
             FROM project_services ps
             JOIN projects p ON p.id = ps.project_id
             LEFT JOIN clients c ON c.id = p.client_id
             WHERE ps.renews_on IS NOT NULL AND ps.renews_on <= :limit
               AND (ps.notified_for IS NULL OR ps.notified_for <> ps.renews_on)
             ORDER BY ps.renews_on',
            ['limit' => date('Y-m-d', (int) strtotime($today . ' +' . $days . ' days'))],
        );
    }

    /** @param array<int, int> $ids */
    public function markNotified(array $ids): void
    {
        foreach ($ids as $id) {
            $this->db->execute('UPDATE project_services SET notified_for = renews_on WHERE id = :id', ['id' => $id]);
        }
    }

    /** Domény k ověření expirace přes RDAP — jednou týdně, nejstarší první. @return array<int, array<string, mixed>> */
    public function domainsToCheck(string $now, int $limit = 10): array
    {
        return $this->db->select(
            "SELECT * FROM project_services
             WHERE kind = 'domain' AND name <> ''
               AND (expiry_checked_at IS NULL OR expiry_checked_at <= DATE_SUB(:now, INTERVAL 7 DAY))
             ORDER BY expiry_checked_at LIMIT " . max(1, $limit),
            ['now' => $now],
        );
    }

    /**
     * Výsledek RDAPu. Zjištěná expirace přepíše ruční datum (registr ví
     * líp); změna data znamená novou obnovu, takže upozornění zase jednou.
     *
     * Registrátor se do `provider` doplní, jen dokud ho nikdo neopravil
     * ručně: pole je prázdné, nebo v něm je to, co se zjistilo minule.
     *
     * @param array{ok: bool, expires_on: ?string, registrar?: ?string, error: ?string} $result
     */
    public function saveExpiry(int $id, array $result, string $now): void
    {
        $data = ['expiry_checked_at' => $now, 'expiry_error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255)];

        if ($result['ok'] && $result['expires_on'] !== null) {
            $data['renews_on'] = $result['expires_on'];
        }

        $registrar = (string) ($result['registrar'] ?? '');
        $service = $this->find($id);

        if ($registrar !== '' && $service !== null) {
            if ((string) $service['provider'] === '' || (string) $service['provider'] === (string) $service['registrar_detected']) {
                $data['provider'] = $registrar;
            }

            $data['registrar_detected'] = $registrar;
        }

        $this->update($id, $data);
    }

    /** Domény ke kontrole DNS pošty — jednou denně, nejstarší první. @return array<int, array<string, mixed>> s `project_name` */
    public function domainsForMailDns(string $now, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT ps.*, p.name AS project_name FROM project_services ps JOIN projects p ON p.id = ps.project_id
             WHERE ps.kind = 'domain' AND ps.name <> ''
               AND (ps.mail_dns_checked_at IS NULL OR ps.mail_dns_checked_at <= DATE_SUB(:now, INTERVAL 20 HOUR))
             ORDER BY ps.mail_dns_checked_at LIMIT " . max(1, $limit),
            ['now' => $now],
        );
    }

    /** @param array<string, mixed> $result z `MailDnsCheck::check()` */
    public function saveMailDns(int $id, array $result, string $now): void
    {
        $this->update($id, ['mail_dns_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'mail_dns_checked_at' => $now]);
    }

    /** Uložený výsledek kontroly DNS pošty, null = zatím nekontrolováno. @param array<string, mixed> $service @return array<string, mixed>|null */
    public static function mailDns(array $service): ?array
    {
        $decoded = json_decode((string) ($service['mail_dns_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : null;
    }

    // -----------------------------------------------------------------
    // Stavy pro obrazovky (bez databáze)
    // -----------------------------------------------------------------

    /** Doména v jednotném tvaru: bez schématu, cesty, www a velkých písmen. */
    public static function normalizeDomain(string $input): string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = explode('/', $host)[0];
        $host = (string) preg_replace('/^www\./', '', $host);

        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) === 1 ? $host : '';
    }

    /** Je doména registrovatelná (`firma.cz`, ne `blog.firma.cz`) — jen ty jde ověřit v RDAPu. */
    public static function isRegistrable(string $domain): bool
    {
        return $domain !== '' && DomainChecker::registrableDomain($domain) === $domain;
    }

    /**
     * Stav obnovy: po termínu / za N dní / datum.
     *
     * @param array<string, mixed> $service
     * @return array{tone: string, label: string, days: ?int}
     */
    public static function renewalState(array $service, string $today, int $days): array
    {
        $renews = (string) ($service['renews_on'] ?? '');

        if ($renews === '') {
            return ['tone' => 'muted', 'label' => 'datum nezadané', 'days' => null];
        }

        $left = (int) round((strtotime($renews) - strtotime($today)) / 86400);
        $date = get_czech_date($renews);

        return match (true) {
            $left < 0 => ['tone' => 'error', 'label' => 'po termínu · ' . $date, 'days' => $left],
            $left === 0 => ['tone' => 'error', 'label' => 'dnes · ' . $date, 'days' => 0],
            $left <= $days => ['tone' => 'warning', 'label' => 'za ' . get_count($left, 'den', 'dny', 'dní') . ' · ' . $date, 'days' => $left],
            default => ['tone' => 'ok', 'label' => $date, 'days' => $left],
        };
    }

    /**
     * Stav fakturace: platí klient / bez ceny / k vyfakturování /
     * vyfakturováno do… `due` = patří do přehledu K vyfakturování.
     *
     * @param array<string, mixed> $service
     * @return array{tone: string, label: string, due: bool}
     */
    public static function billingState(array $service, string $today, int $days): array
    {
        if ((string) $service['paid_by'] === self::PAID_BY_CLIENT) {
            return ['tone' => 'muted', 'label' => 'platí klient', 'due' => false];
        }

        if ((float) ($service['sale_price'] ?? 0) <= 0) {
            return ['tone' => 'muted', 'label' => 'bez ceny', 'due' => false];
        }

        $renews = (string) ($service['renews_on'] ?? '');
        $invoiced = (string) ($service['invoiced_until'] ?? '');

        if ($renews === '') {
            return $invoiced !== ''
                ? ['tone' => 'ok', 'label' => 'vyfakturováno do ' . get_czech_date($invoiced), 'due' => false]
                : ['tone' => 'muted', 'label' => 'bez data obnovy', 'due' => false];
        }

        $nextEnd = self::addMonths($renews, (int) $service['period_months']);
        $covered = $invoiced !== '' && $invoiced >= $nextEnd;
        $soon = $renews <= date('Y-m-d', (int) strtotime($today . ' +' . $days . ' days'));

        return match (true) {
            $covered => ['tone' => 'ok', 'label' => 'vyfakturováno do ' . get_czech_date($invoiced), 'due' => false],
            $soon => ['tone' => 'warning', 'label' => 'k vyfakturování', 'due' => true],
            $invoiced !== '' => ['tone' => 'ok', 'label' => 'vyfakturováno do ' . get_czech_date($invoiced), 'due' => false],
            default => ['tone' => 'muted', 'label' => 'fakturace před obnovou', 'due' => false],
        };
    }

    /** „1 290 Kč / rok", bez ceny prázdný řetězec. @param array<string, mixed> $service */
    public static function priceLabel(array $service, string $column = 'sale_price'): string
    {
        $price = $service[$column] ?? null;

        if ($price === null || (float) $price <= 0) {
            return '';
        }

        return self::money((float) $price, (string) $service['currency']) . ' / ' . (self::PERIOD_SUFFIX[(int) $service['period_months']] ?? $service['period_months'] . ' měs.');
    }

    /** „1 290 Kč", „12,50 €" — haléře jen když nějaké jsou. */
    public static function money(float $amount, string $currency): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return str_replace(' ', "\u{00A0}", number_format($amount, $decimals, ',', ' ')) . "\u{00A0}" . (self::CURRENCIES[$currency] ?? $currency);
    }

    /** Datum + měsíce bez přetečení (31. 1. + 1 měsíc = 28./29. 2.). */
    public static function addMonths(string $date, int $months): string
    {
        $base = new \DateTimeImmutable($date);
        $target = $base->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $base->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)->format('Y-m-d');
    }
}
