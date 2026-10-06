<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Monitor\MailDnsCheck;
use App\Core\Projects\ProjectServices;

/**
 * Domény a hosting projektu (`ProjectServices`) a přehled Obnovy
 * a fakturace. Návrh obrazovky nemá — tabulky a formuláře jako jinde.
 *
 * Akce v řádku (Vyfakturováno, Obnoveno, smazat) jdou z detailu projektu
 * i z přehledu; skryté pole `back=obnovy` vrátí na přehled.
 */
final class ProjectServiceController extends Controller
{
    /** Filtry přehledu: '' = vše, `brzy` = obnova do N dní, `fakturace` = k vyfakturování. */
    private const FILTERS = ['', 'brzy', 'fakturace'];

    public function overview(): Response
    {
        $filter = in_array($this->request()->string('filtr'), self::FILTERS, true) ? $this->request()->string('filtr') : '';
        $today = date('Y-m-d');
        $days = $this->kernel->monitorSettings()->int('rule_renewal_days');

        $all = array_map(static fn (array $s): array => self::row($s, $today, $days), $this->kernel->projectServices()->all());
        $counts = [
            '' => count($all),
            'brzy' => count(array_filter($all, static fn (array $r): bool => $r['soon'])),
            'fakturace' => count(array_filter($all, static fn (array $r): bool => $r['billing']['due'])),
        ];
        $rows = match ($filter) {
            'brzy' => array_values(array_filter($all, static fn (array $r): bool => $r['soon'])),
            'fakturace' => array_values(array_filter($all, static fn (array $r): bool => $r['billing']['due'])),
            default => $all,
        };

        // K vyfakturování po klientech se součtem — podklad pro fakturu.
        $groups = [];

        if ($filter === 'fakturace') {
            foreach ($rows as $row) {
                $client = (string) ($row['client_name'] ?? '') !== '' ? (string) $row['client_name'] : 'Bez klienta';
                $groups[$client]['rows'][] = $row;
                $groups[$client]['sums'][(string) $row['currency']] = ($groups[$client]['sums'][(string) $row['currency']] ?? 0.0) + (float) $row['sale_price'];
            }

            ksort($groups);

            foreach ($groups as $client => $group) {
                $groups[$client]['total'] = implode(' + ', array_map(
                    static fn (string $currency, float $sum): string => ProjectServices::money($sum, $currency),
                    array_keys($group['sums']),
                    $group['sums'],
                ));
            }
        }

        return $this->view('projects/renewals', [
            'title' => 'Obnovy a fakturace',
            'rows' => $rows,
            'groups' => $groups,
            'filter' => $filter,
            'counts' => $counts,
            'days' => $days,
            'tabsHtml' => ProjectController::listTabs('obnovy'),
        ]);
    }

    public function createForm(string $id, string $kind): Response
    {
        $project = $this->projectOr404((int) $id);
        $kind = $this->kindOr404($kind);

        return $this->formPage($project, $kind, null, self::emptyValues(), []);
    }

    public function store(string $id, string $kind): Response
    {
        $project = $this->projectOr404((int) $id);
        $kind = $this->kindOr404($kind);
        [$values, $errors] = $this->readForm($kind);

        if ($errors !== []) {
            return $this->formPage($project, $kind, null, $values, $errors, 422);
        }

        $this->kernel->projectServices()->create((int) $id, $kind, self::columns($kind, $values));
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Přidána služba ' . self::label($kind, $values) . ' k projektu ' . $project['name']);

        return $this->redirectWithFlash('projekty/' . $id, $kind === ProjectServices::KIND_DOMAIN && $values['renews_on'] === ''
            ? 'Doména je přidaná. Expiraci zjistí monitor z registru do hodiny.'
            : ProjectServices::KINDS[$kind]['label'] . ' je přidaný k projektu.');
    }

    public function editForm(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);
        $values = self::emptyValues();

        foreach (array_keys($values) as $key) {
            $values[$key] = $service[$key] !== null ? (string) $service[$key] : '';
        }

        foreach (['cost_price', 'sale_price'] as $key) {
            $values[$key] = $service[$key] !== null ? str_replace('.', ',', rtrim(rtrim((string) $service[$key], '0'), '.,')) : '';
        }

        return $this->formPage($project, (string) $service['kind'], $service, $values, []);
    }

    public function update(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);
        $kind = (string) $service['kind'];
        [$values, $errors] = $this->readForm($kind);

        if ($errors !== []) {
            return $this->formPage($project, $kind, $service, $values, $errors, 422);
        }

        $columns = self::columns($kind, $values);

        // Jiná doména = jiná expirace; ověří se znovu.
        if ($kind === ProjectServices::KIND_DOMAIN && $values['name'] !== (string) $service['name']) {
            $columns += ['expiry_checked_at' => null, 'expiry_error' => null];
        }

        $this->kernel->projectServices()->update((int) $serviceId, $columns);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Upravena služba ' . self::label($kind, $values) . ' u projektu ' . $project['name']);

        return $this->redirectWithFlash('projekty/' . $id, 'Služba je uložená.');
    }

    public function delete(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);
        $this->kernel->projectServices()->delete((int) $serviceId);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Smazána služba ' . self::label((string) $service['kind'], $service) . ' u projektu ' . $project['name']);

        return $this->back($project, 'Služba je smazaná.');
    }

    /** Klientovi je vyfakturované příští období. */
    public function invoiced(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);
        $this->kernel->projectServices()->markInvoiced((int) $serviceId);
        $fresh = $this->kernel->projectServices()->find((int) $serviceId) ?? $service;
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Vyfakturováno: ' . self::label((string) $service['kind'], $service) . ' do ' . get_czech_date((string) $fresh['invoiced_until']));

        return $this->back($project, 'Zapsáno — vyfakturováno do ' . get_czech_date((string) $fresh['invoiced_until']) . '.');
    }

    /** Služba je u poskytovatele zaplacená na další období. */
    public function renewed(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);
        $this->kernel->projectServices()->markRenewed((int) $serviceId);
        $fresh = $this->kernel->projectServices()->find((int) $serviceId) ?? $service;
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Obnoveno: ' . self::label((string) $service['kind'], $service) . ' do ' . get_czech_date((string) $fresh['renews_on']));

        return $this->back($project, 'Zapsáno — další obnova ' . get_czech_date((string) $fresh['renews_on']) . '.');
    }

    /** „Zkontrolovat DNS teď" v úpravě domény — DNS odpoví hned, výsledek se ukáže po návratu. */
    public function checkMailDns(string $id, string $serviceId): Response
    {
        $project = $this->projectOr404((int) $id);
        $service = $this->serviceOr404($project, (int) $serviceId);

        if ((string) $service['kind'] !== ProjectServices::KIND_DOMAIN) {
            throw HttpException::notFound('DNS pošty se kontroluje jen u domény.');
        }

        $this->kernel->mailDns()->checkOne($service, date('Y-m-d H:i:s'));
        $summary = MailDnsCheck::summary(ProjectServices::mailDns($this->kernel->projectServices()->find((int) $serviceId) ?? $service));

        return $this->redirectWithFlash('projekty/' . $id . '/sluzby/' . $serviceId . '/upravit', 'DNS zkontrolováno: ' . $summary['label'] . '.');
    }

    /**
     * Řádek služby pro tabulky: stav obnovy a fakturace, cena, název.
     * Používá ho i detail projektu.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    public static function row(array $service, string $today, int $days): array
    {
        $renewal = ProjectServices::renewalState($service, $today, $days);
        $billing = ProjectServices::billingState($service, $today, $days);
        $kind = ProjectServices::KINDS[(string) $service['kind']] ?? ProjectServices::KINDS[ProjectServices::KIND_HOSTING];

        $isDomain = (string) $service['kind'] === ProjectServices::KIND_DOMAIN;

        // Doména: „pekarnanovak.cz" / „Doména · Wedos". Hosting: „Wedos NoLimit" / „Hosting · označení".
        return $service + [
            'kindLabel' => $kind['label'],
            'icon' => $kind['icon'],
            'title' => $isDomain ? (string) $service['name'] : trim($service['provider'] . ' ' . $service['plan']),
            'subtitle' => implode(' · ', array_filter([
                $isDomain ? (string) $service['provider'] : (string) $service['name'],
                $isDomain && (string) ($service['expiry_error'] ?? '') !== '' ? 'registr neodpověděl' : '',
            ])),
            'renewal' => $renewal,
            // Stav pošty jen u domény (DNS se kontroluje denně).
            'mail' => $isDomain ? MailDnsCheck::summary(ProjectServices::mailDns($service)) : null,
            'soon' => $renewal['days'] !== null && $renewal['days'] <= $days,
            'billing' => $billing,
            'price' => ProjectServices::priceLabel($service),
            'cost' => ProjectServices::priceLabel($service, 'cost_price'),
            'base' => 'projekty/' . (int) $service['project_id'] . '/sluzby/' . (int) $service['id'],
        ];
    }

    // -----------------------------------------------------------------
    // Formulář
    // -----------------------------------------------------------------

    /** @return array<string, string> */
    private static function emptyValues(): array
    {
        return [
            'name' => '', 'provider' => '', 'plan' => '', 'renews_on' => '',
            'paid_by' => ProjectServices::PAID_BY_US, 'cost_price' => '', 'sale_price' => '', 'currency' => 'CZK',
            'period_months' => '12', 'invoiced_until' => '', 'note' => '',
        ];
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function readForm(string $kind): array
    {
        $request = $this->request();
        $values = self::emptyValues();

        foreach (array_keys($values) as $key) {
            $values[$key] = trim(mb_substr($request->string($key), 0, $key === 'note' ? 5000 : 190));
        }

        $errors = [];

        if ($kind === ProjectServices::KIND_DOMAIN) {
            $values['name'] = ProjectServices::normalizeDomain($values['name']);

            if ($values['name'] === '') {
                $errors['name'] = 'Zadejte doménu ve tvaru firma.cz.';
            }
        } elseif ($values['provider'] === '') {
            $errors['provider'] = 'Napište, u koho hosting je (Wedos, Forpsi…).';
        }

        foreach (['renews_on' => 'Datum obnovy', 'invoiced_until' => 'Vyfakturováno do'] as $key => $label) {
            if ($values[$key] !== '' && \DateTime::createFromFormat('Y-m-d', $values[$key]) === false) {
                $errors[$key] = $label . ': zadejte datum.';
            }
        }

        foreach (['cost_price' => 'Nákupní cena', 'sale_price' => 'Prodejní cena'] as $key => $label) {
            $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $values[$key]);

            if ($normalized !== '' && !is_numeric($normalized)) {
                $errors[$key] = $label . ': zadejte číslo, třeba 1290 nebo 249,50.';
            }

            $values[$key] = $normalized;
        }

        $values['paid_by'] = $values['paid_by'] === ProjectServices::PAID_BY_CLIENT ? ProjectServices::PAID_BY_CLIENT : ProjectServices::PAID_BY_US;
        $values['currency'] = isset(ProjectServices::CURRENCIES[$values['currency']]) ? $values['currency'] : 'CZK';
        $values['period_months'] = isset(ProjectServices::PERIODS[(int) $values['period_months']]) ? $values['period_months'] : '12';

        return [$values, $errors];
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private static function columns(string $kind, array $values): array
    {
        return [
            'name' => $values['name'],
            'provider' => mb_substr($values['provider'], 0, 120),
            'plan' => $kind === ProjectServices::KIND_HOSTING ? mb_substr($values['plan'], 0, 120) : '',
            'renews_on' => $values['renews_on'] !== '' ? $values['renews_on'] : null,
            'paid_by' => $values['paid_by'],
            'cost_price' => $values['cost_price'] !== '' ? round((float) $values['cost_price'], 2) : null,
            'sale_price' => $values['sale_price'] !== '' ? round((float) $values['sale_price'], 2) : null,
            'currency' => $values['currency'],
            'period_months' => (int) $values['period_months'],
            'invoiced_until' => $values['invoiced_until'] !== '' ? $values['invoiced_until'] : null,
            'note' => $values['note'] !== '' ? $values['note'] : null,
        ];
    }

    /** „pekarnanovak.cz", „Hosting Wedos NoLimit". @param array<string, mixed> $values */
    private static function label(string $kind, array $values): string
    {
        if ($kind === ProjectServices::KIND_DOMAIN) {
            return (string) $values['name'];
        }

        return trim('Hosting ' . ((string) $values['name'] !== '' ? $values['name'] : $values['provider']) . ' ' . ($values['plan'] ?? ''));
    }

    /**
     * @param array<string, mixed>      $project
     * @param array<string, mixed>|null $service null = nová
     * @param array<string, string>     $values
     * @param array<string, string>     $errors
     */
    private function formPage(array $project, string $kind, ?array $service, array $values, array $errors, int $status = 200): Response
    {
        $kindLabel = ProjectServices::KINDS[$kind]['label'];

        return $this->view('projects/service-form', [
            'title' => ($service === null ? 'Přidat ' : 'Upravit ') . mb_strtolower($kindLabel === 'Doména' ? 'doménu' : $kindLabel),
            'project' => $project,
            'kind' => $kind,
            'service' => $service,
            'values' => $values,
            'errors' => $errors,
            'action' => $service === null
                ? get_url('projekty/' . (int) $project['id'] . '/sluzby/pridat/' . ProjectServices::KINDS[$kind]['slug'])
                : get_url('projekty/' . (int) $project['id'] . '/sluzby/' . (int) $service['id'] . '/upravit'),
            'periods' => ProjectServices::PERIODS,
            'currencies' => ProjectServices::CURRENCIES,
            'expiryNote' => $service !== null && $kind === ProjectServices::KIND_DOMAIN ? self::expiryNote($service) : '',
            'mailDns' => $service !== null && $kind === ProjectServices::KIND_DOMAIN ? self::mailDnsView($service) : null,
            'dnsAction' => $service !== null ? get_url('projekty/' . (int) $project['id'] . '/sluzby/' . (int) $service['id'] . '/dns') : '',
        ], $status);
    }

    /**
     * Rozpis poslední kontroly DNS pošty pro úpravu domény: záznamy
     * a vysvětlení problémů.
     *
     * @param array<string, mixed> $service
     * @return array{summary: array{tone: string, label: string}, checkedAt: string, rows: array<int, array{label: string, value: string}>, issues: array<int, array{tone: string, text: string}>}
     */
    private static function mailDnsView(array $service): array
    {
        $result = ProjectServices::mailDns($service);
        $rows = [];
        $issues = [];

        if ($result !== null && ($result['ok'] ?? false)) {
            $policy = ['none' => 'jen sleduje', 'quarantine' => 'do spamu', 'reject' => 'odmítnout'][(string) ($result['dmarc_policy'] ?? '')] ?? '';
            $rows = [
                ['label' => 'MX', 'value' => $result['mx'] !== [] ? implode(', ', $result['mx']) : '—'],
                ['label' => 'SPF', 'value' => (string) ($result['spf'] ?? '—')],
                ['label' => 'DMARC', 'value' => $result['dmarc'] !== null ? ($policy !== '' ? 'politika ' . $policy . ' · ' : '') . $result['dmarc'] : '—'],
                ['label' => 'DKIM', 'value' => $result['dkim'] !== null ? 'selektor ' . $result['dkim'] : 'nezjištěno'],
            ];

            foreach ((array) $result['issues'] as $key) {
                if (isset(MailDnsCheck::ISSUES[$key])) {
                    $issues[] = ['tone' => MailDnsCheck::ISSUES[$key]['tone'], 'text' => MailDnsCheck::ISSUES[$key]['text']];
                }
            }
        }

        return [
            'summary' => MailDnsCheck::summary($result),
            'checkedAt' => ($service['mail_dns_checked_at'] ?? null) !== null ? get_when((string) $service['mail_dns_checked_at']) : '',
            'rows' => $rows,
            'issues' => $issues,
        ];
    }

    /** Co o expiraci zjistil registr. @param array<string, mixed> $service */
    private static function expiryNote(array $service): string
    {
        return match (true) {
            ($service['expiry_checked_at'] ?? null) === null => 'Expirace se zjistí z registru do hodiny.',
            (string) ($service['expiry_error'] ?? '') !== '' => 'Registr naposledy neodpověděl (' . $service['expiry_error'] . ') — datum zadejte ručně.',
            default => 'Ověřeno v registru ' . get_when((string) $service['expiry_checked_at']) . '.',
        };
    }

    /** @param array<string, mixed> $project */
    private function back(array $project, string $message): Response
    {
        return $this->redirectWithFlash($this->request()->string('back') === 'obnovy' ? 'projekty/obnovy' : 'projekty/' . (int) $project['id'], $message);
    }

    /** @return array<string, mixed> */
    private function projectOr404(int $id): array
    {
        return $this->kernel->projects()->find($id) ?? throw HttpException::notFound('Projekt neexistuje.');
    }

    /** Služba jen z tohoto projektu — cizí číslo v adrese je 404. @param array<string, mixed> $project @return array<string, mixed> */
    private function serviceOr404(array $project, int $serviceId): array
    {
        $service = $this->kernel->projectServices()->find($serviceId);

        if ($service === null || (int) $service['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Služba neexistuje.');
        }

        return $service;
    }

    private function kindOr404(string $slug): string
    {
        return ProjectServices::kindFromSlug($slug) ?? throw HttpException::notFound('Neznámý druh služby.');
    }
}
