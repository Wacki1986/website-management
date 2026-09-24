<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Sites\SiteCredentials;

/**
 * Detail webu — Přístupy: trezor s FTP, hostingem, databází a dalším.
 *
 * Trezor otevře jen účet se zapnutým dvoufázovým přihlášením. Samotné heslo
 * jako jediné ze správy nechodí ve stránce: výpis ukazuje tečky a heslo si
 * oko nebo tlačítko kopírování dotáhne zvlášť (`password()`, `vault.js`).
 * Nezůstane tak v HTML, v historii prohlížeče ani v náhledu stránky.
 */
final class CredentialController extends Controller
{
    use SiteHeaderTrait;

    public function index(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $base = 'weby/' . (int) $site['id'] . '/pristupy';
        $locked = !$this->unlocked();
        $rows = [];

        if (!$locked) {
            foreach ($this->kernel->credentials()->forSite((int) $site['id']) as $credential) {
                $rows[] = $this->row($base, $credential);
            }
        }

        $addLinks = [];

        foreach (SiteCredentials::KINDS as $kind => $meta) {
            $addLinks[] = ['label' => $meta['label'], 'url' => get_url($base . '/pridat/' . $kind)];
        }

        return $this->view('sites/credentials', $this->header($site, 'pristupy') + [
            'locked' => $locked,
            'available' => $this->kernel->credentials()->isAvailable(),
            'rows' => $rows,
            'addLinks' => $addLinks,
            'setupUrl' => get_url('nastaveni/dvoufazove'),
        ]);
    }

    public function createForm(string $id, string $kind): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();

        return $this->form($site, self::kindOr404($kind), null, self::emptyValues($kind), []);
    }

    public function store(string $id, string $kind): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();
        $kind = self::kindOr404($kind);
        $values = $this->submitted();
        $errors = self::validate($kind, $values);

        if ($errors !== []) {
            return $this->form($site, $kind, null, $values, $errors, 422);
        }

        $user = $this->kernel->auth()->current();
        $this->kernel->credentials()->create((int) $site['id'], $kind, $values, $user !== null ? (int) $user['id'] : null);
        $this->kernel->audit()->record((int) $site['id'], (string) $site['name'], AuditLog::ACTION_SITE_EDIT, true,
            'Přístupy: přidán ' . self::title($kind, $values));

        return $this->redirectWithFlash('weby/' . (int) $site['id'] . '/pristupy', 'Přístup je uložený.');
    }

    public function editForm(string $id, string $credentialId): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();
        $credential = $this->credentialOr404((int) $site['id'], (int) $credentialId);

        return $this->form($site, (string) $credential['kind'], $credential, $credential + ['password' => ''], []);
    }

    public function update(string $id, string $credentialId): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();
        $credential = $this->credentialOr404((int) $site['id'], (int) $credentialId);
        $kind = (string) $credential['kind'];
        $values = $this->submitted();
        $errors = self::validate($kind, $values);

        if ($errors !== []) {
            return $this->form($site, $kind, $credential, $values, $errors, 422);
        }

        $this->kernel->credentials()->update((int) $site['id'], (int) $credential['id'], $kind, $values);
        $this->kernel->audit()->record((int) $site['id'], (string) $site['name'], AuditLog::ACTION_SITE_EDIT, true,
            'Přístupy: upraven ' . self::title($kind, $values));

        return $this->redirectWithFlash('weby/' . (int) $site['id'] . '/pristupy', 'Přístup je uložený.');
    }

    public function delete(string $id, string $credentialId): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();
        $credential = $this->credentialOr404((int) $site['id'], (int) $credentialId);

        $this->kernel->credentials()->delete((int) $site['id'], (int) $credential['id']);
        $this->kernel->audit()->record((int) $site['id'], (string) $site['name'], AuditLog::ACTION_SITE_EDIT, true,
            'Přístupy: smazán ' . self::title((string) $credential['kind'], $credential));

        return $this->redirectWithFlash('weby/' . (int) $site['id'] . '/pristupy', 'Přístup je smazaný.');
    }

    /**
     * Heslo pro oko a kopírování (JSON). `format=filezilla` vrátí rovnou
     * adresu pro rychlé připojení FileZilly i s heslem.
     */
    public function password(string $id, string $credentialId): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->requireUnlocked();
        $credential = $this->credentialOr404((int) $site['id'], (int) $credentialId);
        $password = $this->kernel->credentials()->password((int) $site['id'], (int) $credential['id']);

        if ($password === null && $credential['has_password']) {
            // Uložené heslo nejde rozšifrovat — vyměněný app_key.
            return $this->json(['ok' => false, 'message' => 'Heslo nejde rozšifrovat — změnil se app_key v config/env.php. Uložte ho znovu.'], 500);
        }

        $value = $this->request()->string('format') === 'filezilla'
            ? SiteCredentials::fileZillaUrl($credential, $password ?? '')
            : ($password ?? '');

        // Heslo se nikde nesmí usadit — ani v cache prohlížeče nebo proxy.
        return $this->json(['ok' => true, 'value' => $value])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Formulář přidání i úpravy — pole podle druhu (`SiteCredentials::KINDS`).
     *
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $credential null = nový
     * @param array<string, mixed>      $values
     * @param array<string, string>     $errors
     */
    private function form(array $site, string $kind, ?array $credential, array $values, array $errors, int $status = 200): Response
    {
        $base = 'weby/' . (int) $site['id'] . '/pristupy';
        $protocols = [];

        foreach (SiteCredentials::PROTOCOLS as $code => $protocol) {
            $protocols[$code] = $protocol['label'];
        }

        return $this->view('sites/credential-form', $this->header($site, 'pristupy') + [
            'kind' => $kind,
            'kindLabel' => SiteCredentials::KINDS[$kind]['label'],
            'fields' => array_fill_keys(SiteCredentials::KINDS[$kind]['fields'], true),
            'urlLabel' => match ($kind) {
                'hosting' => 'Odkaz na administraci',
                'database' => 'Odkaz na phpMyAdmin',
                default => 'Adresa',
            },
            'hostLabel' => $kind === 'database' ? 'Server databáze' : 'Server',
            'hostPlaceholder' => $kind === 'database' ? 'localhost' : 'ftp.example.cz',
            'hostRequired' => $kind === 'ftp',
            'values' => $values,
            'errors' => $errors,
            'isEdit' => $credential !== null,
            'hasPassword' => (bool) ($credential['has_password'] ?? false),
            'protocols' => $protocols,
            'formAction' => get_url($credential !== null ? $base . '/' . (int) $credential['id'] . '/upravit' : $base . '/pridat/' . $kind),
            'backUrl' => get_url($base),
        ], $status);
    }

    /**
     * Řádek výpisu — jen hotové texty a adresy, heslo ne.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function row(string $base, array $credential): array
    {
        $kind = (string) $credential['kind'];
        $url = $base . '/' . (int) $credential['id'];
        $protocol = (string) $credential['protocol'];
        $port = $credential['port'] ?? (SiteCredentials::PROTOCOLS[$protocol]['port'] ?? null);

        // Druhý řádek pod názvem: u FTP protokol, u databáze jméno databáze.
        $detail = match ($kind) {
            'ftp' => strtoupper($protocol !== '' ? $protocol : 'ftp'),
            'database' => (string) $credential['database_name'] !== '' ? 'databáze ' . $credential['database_name'] : '',
            default => '',
        };

        return [
            'title' => self::title($kind, $credential),
            'icon' => SiteCredentials::KINDS[$kind]['icon'],
            // Pod názvem: detail a poznámka; celá poznámka je v bublině.
            'subline' => implode(' · ', array_filter([$detail, (string) $credential['note']], static fn (string $part): bool => $part !== '')),
            'note' => (string) $credential['note'],
            'server' => $kind === 'ftp' || ($kind === 'database' && (string) $credential['url'] === '')
                ? trim((string) $credential['host'] . ($kind === 'ftp' && $port !== null && (string) $credential['host'] !== '' ? ':' . $port : ''))
                : (string) $credential['url'],
            // Odkaz jen na skutečnou webovou adresu — `javascript:` z formuláře neprojde.
            'openUrl' => preg_match('~^https?://~i', (string) $credential['url']) === 1 ? (string) $credential['url'] : '',
            'username' => (string) $credential['username'],
            'hasPassword' => (bool) $credential['has_password'],
            'passwordUrl' => get_url($url . '/heslo'),
            'fileZilla' => $kind === 'ftp' && (string) $credential['host'] !== '',
            'editUrl' => get_url($url . '/upravit'),
            'deleteUrl' => get_url($url . '/smazat'),
        ];
    }

    /**
     * Co přišlo z formuláře — jen známá pole, všechna jako text.
     *
     * @return array<string, string>
     */
    private function submitted(): array
    {
        $request = $this->request();
        $values = [];

        foreach (['label', 'protocol', 'host', 'port', 'url', 'database_name', 'username', 'password', 'note'] as $field) {
            // Heslo se neořezává — mezera na kraji může být jeho součástí.
            $values[$field] = $field === 'password' ? (string) $request->input($field, '') : trim($request->string($field));
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string> pole => hláška
     */
    private static function validate(string $kind, array $values): array
    {
        $errors = [];

        if ($kind === 'ftp' && $values['host'] === '') {
            $errors['host'] = 'Vyplňte server, na který se FileZilla připojuje.';
        }

        if ($kind === 'other' && $values['label'] === '') {
            $errors['label'] = 'Pojmenujte přístup, ať je ve výpisu jasné, k čemu je.';
        }

        if ($values['port'] !== '' && (!ctype_digit($values['port']) || (int) $values['port'] < 1 || (int) $values['port'] > 65535)) {
            $errors['port'] = 'Port je číslo od 1 do 65535 — nebo nechte prázdné pro výchozí.';
        }

        if ($values['url'] !== '' && preg_match('~^https?://~i', $values['url']) !== 1) {
            $errors['url'] = 'Adresa musí začínat https:// (nebo http://).';
        }

        return $errors;
    }

    /**
     * Název přístupu ve výpisu a v historii webu: vlastní popisek, jinak druh.
     *
     * @param array<string, mixed> $values
     */
    private static function title(string $kind, array $values): string
    {
        $label = trim((string) ($values['label'] ?? ''));

        return $label !== '' ? $label : SiteCredentials::KINDS[$kind]['label'];
    }

    /** @return array<string, string> */
    private static function emptyValues(string $kind): array
    {
        return [
            'label' => '',
            // Nový FTP rovnou šifrovaně — nešifrované FTP má být vědomá volba.
            'protocol' => $kind === 'ftp' ? 'sftp' : '',
            'host' => $kind === 'database' ? 'localhost' : '',
            'port' => '',
            'url' => '',
            'database_name' => '',
            'username' => '',
            'password' => '',
            'note' => '',
        ];
    }

    private static function kindOr404(string $kind): string
    {
        if (!isset(SiteCredentials::KINDS[$kind])) {
            throw HttpException::notFound('Neznámý druh přístupu.');
        }

        return $kind;
    }

    /** @return array<string, mixed> */
    private function credentialOr404(int $siteId, int $id): array
    {
        return $this->kernel->credentials()->find($siteId, $id)
            ?? throw HttpException::notFound('Přístup neexistuje, nebo patří jinému webu.');
    }

    /** Má přihlášený účet zapnuté dvoufázové přihlášení? */
    private function unlocked(): bool
    {
        $user = $this->kernel->auth()->current();

        return $user !== null && $this->kernel->twoFactor()->isEnabled($user);
    }

    /**
     * Pojistka pro všechno kromě výpisu — výpis místo chyby ukáže, jak
     * trezor odemknout.
     */
    private function requireUnlocked(): void
    {
        if (!$this->unlocked()) {
            throw new HttpException(403, 'Trezor přístupů otevře jen účet se zapnutým dvoufázovým přihlášením (Nastavení → Uživatelé).');
        }

        if (!$this->kernel->credentials()->isAvailable()) {
            throw new HttpException(503, 'Chybí app_key v config/env.php — bez něj se přístupy nedají bezpečně uložit ani přečíst.');
        }
    }

    /** @return array<string, mixed> */
    private function siteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        return $site;
    }
}
