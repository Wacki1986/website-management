<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Projects\Credentials;

/**
 * Trezor přístupů projektu — FTP, hosting, databáze, e-maily, registrátor.
 *
 * Trezor patří projektu a otevírá se ze dvou míst se stejným obsahem:
 * záložka Přístupy u webu (`/weby/{id}/pristupy…`, hlavička webu) a detail
 * projektu (`/projekty/{id}/pristupy…`, hlavička projektu). Veřejné metody
 * jsou proto po dvou a liší se jen tím, jaký „kontext" si připraví
 * (`siteContext()` / `projectContext()`); práce je společná.
 *
 * Trezor otevře jen účet se zapnutým dvoufázovým přihlášením. Samotné heslo
 * jako jediné ze správy nechodí ve stránce: výpis ukazuje tečky a heslo si
 * oko nebo tlačítko kopírování dotáhne zvlášť (`password()`, `vault.js`).
 * Nezůstane tak v HTML, v historii prohlížeče ani v náhledu stránky.
 */
final class CredentialController extends Controller
{
    use SiteHeaderTrait;

    // --- záložka Přístupy u webu ---------------------------------------

    public function index(string $id): Response
    {
        return $this->showIndex($this->siteContext((int) $id));
    }

    public function createForm(string $id, string $kind): Response
    {
        return $this->showCreateForm($this->siteContext((int) $id), $kind);
    }

    public function store(string $id, string $kind): Response
    {
        return $this->doStore($this->siteContext((int) $id), $kind);
    }

    public function editForm(string $id, string $credentialId): Response
    {
        return $this->showEditForm($this->siteContext((int) $id), (int) $credentialId);
    }

    public function update(string $id, string $credentialId): Response
    {
        return $this->doUpdate($this->siteContext((int) $id), (int) $credentialId);
    }

    public function delete(string $id, string $credentialId): Response
    {
        return $this->doDelete($this->siteContext((int) $id), (int) $credentialId);
    }

    public function password(string $id, string $credentialId): Response
    {
        return $this->revealPassword($this->siteContext((int) $id), (int) $credentialId);
    }

    // --- trezor v detailu projektu --------------------------------------

    public function projectIndex(string $id): Response
    {
        return $this->showIndex($this->projectContext((int) $id));
    }

    public function projectCreateForm(string $id, string $kind): Response
    {
        return $this->showCreateForm($this->projectContext((int) $id), $kind);
    }

    public function projectStore(string $id, string $kind): Response
    {
        return $this->doStore($this->projectContext((int) $id), $kind);
    }

    public function projectEditForm(string $id, string $credentialId): Response
    {
        return $this->showEditForm($this->projectContext((int) $id), (int) $credentialId);
    }

    public function projectUpdate(string $id, string $credentialId): Response
    {
        return $this->doUpdate($this->projectContext((int) $id), (int) $credentialId);
    }

    public function projectDelete(string $id, string $credentialId): Response
    {
        return $this->doDelete($this->projectContext((int) $id), (int) $credentialId);
    }

    public function projectPassword(string $id, string $credentialId): Response
    {
        return $this->revealPassword($this->projectContext((int) $id), (int) $credentialId);
    }

    // --- společná práce ---------------------------------------------------

    /** @param array<string, mixed> $context */
    private function showIndex(array $context): Response
    {
        $locked = !$this->unlocked();
        $rows = [];

        if (!$locked) {
            foreach ($this->kernel->credentials()->forProject($context['projectId']) as $credential) {
                $rows[] = $this->row($context['base'], $credential);
            }
        }

        $addLinks = [];

        foreach (Credentials::KINDS as $kind => $meta) {
            $addLinks[] = ['label' => $meta['label'], 'url' => get_url($context['base'] . '/pridat/' . $kind)];
        }

        return $this->view('vault/index', $context['view'] + [
            'locked' => $locked,
            'available' => $this->kernel->credentials()->isAvailable(),
            'rows' => $rows,
            'addLinks' => $addLinks,
            'setupUrl' => get_url('nastaveni/dvoufazove'),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function showCreateForm(array $context, string $kind): Response
    {
        $this->requireUnlocked();
        $kind = self::kindOr404($kind);

        return $this->form($context, $kind, null, self::emptyValues($kind), []);
    }

    /** @param array<string, mixed> $context */
    private function doStore(array $context, string $kind): Response
    {
        $this->requireUnlocked();
        $kind = self::kindOr404($kind);
        $values = $this->submitted();
        $errors = self::validate($kind, $values);

        if ($errors !== []) {
            return $this->form($context, $kind, null, $values, $errors, 422);
        }

        $user = $this->kernel->auth()->current();
        $this->kernel->credentials()->create($context['projectId'], $context['siteId'], $kind, $values, $user !== null ? (int) $user['id'] : null);
        $this->audit($context, 'Přístupy: přidán ' . self::title($kind, $values));

        return $this->redirectWithFlash($context['base'], 'Přístup je uložený.');
    }

    /** @param array<string, mixed> $context */
    private function showEditForm(array $context, int $credentialId): Response
    {
        $this->requireUnlocked();
        $credential = $this->credentialOr404($context['projectId'], $credentialId);

        return $this->form($context, (string) $credential['kind'], $credential, $credential + ['password' => ''], []);
    }

    /** @param array<string, mixed> $context */
    private function doUpdate(array $context, int $credentialId): Response
    {
        $this->requireUnlocked();
        $credential = $this->credentialOr404($context['projectId'], $credentialId);
        $kind = (string) $credential['kind'];
        $values = $this->submitted();
        $errors = self::validate($kind, $values);

        if ($errors !== []) {
            return $this->form($context, $kind, $credential, $values, $errors, 422);
        }

        $this->kernel->credentials()->update($context['projectId'], (int) $credential['id'], $kind, $values);
        $this->audit($context, 'Přístupy: upraven ' . self::title($kind, $values));

        return $this->redirectWithFlash($context['base'], 'Přístup je uložený.');
    }

    /** @param array<string, mixed> $context */
    private function doDelete(array $context, int $credentialId): Response
    {
        $this->requireUnlocked();
        $credential = $this->credentialOr404($context['projectId'], $credentialId);

        $this->kernel->credentials()->delete($context['projectId'], (int) $credential['id']);
        $this->audit($context, 'Přístupy: smazán ' . self::title((string) $credential['kind'], $credential));

        return $this->redirectWithFlash($context['base'], 'Přístup je smazaný.');
    }

    /**
     * Heslo pro oko a kopírování (JSON). `format=filezilla` vrátí rovnou
     * adresu pro rychlé připojení FileZilly i s heslem.
     *
     * @param array<string, mixed> $context
     */
    private function revealPassword(array $context, int $credentialId): Response
    {
        $this->requireUnlocked();
        $credential = $this->credentialOr404($context['projectId'], $credentialId);
        $password = $this->kernel->credentials()->password($context['projectId'], (int) $credential['id']);

        if ($password === null && $credential['has_password']) {
            // Uložené heslo nejde rozšifrovat — vyměněný app_key.
            return $this->json(['ok' => false, 'message' => 'Heslo nejde rozšifrovat — změnil se app_key v config/env.php. Uložte ho znovu.'], 500);
        }

        $value = $this->request()->string('format') === 'filezilla'
            ? Credentials::fileZillaUrl($credential, $password ?? '')
            : ($password ?? '');

        // Heslo se nikde nesmí usadit — ani v cache prohlížeče nebo proxy.
        return $this->json(['ok' => true, 'value' => $value])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Záložka Přístupy u webu: trezor projektu webu. Web z doby před
     * projekty (bez projektu) ho dostane hned, ať má přístup kam uložit.
     *
     * @return array<string, mixed> kontext pro společné metody
     */
    private function siteContext(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        if ($site['project_id'] === null) {
            $this->kernel->projects()->createForSite((int) $site['id'], (string) $site['name'], $site['client_id'] !== null ? (int) $site['client_id'] : null);
            $site = $this->kernel->sites()->findWithSnapshot($id) ?? $site;
        }

        return [
            'projectId' => (int) $site['project_id'],
            'siteId' => (int) $site['id'],
            'base' => 'weby/' . (int) $site['id'] . '/pristupy',
            'auditSiteId' => (int) $site['id'],
            'auditName' => (string) $site['name'],
            'view' => $this->header($site, 'pristupy') + [
                'headerPartial' => 'partials/site-header',
                'pageTitle' => (string) $site['name'],
                'ownerNote' => 'Přístupy patří projektu ' . $site['project_name'] . ' — stejné uvidíte u jeho ostatních webů a zůstanou, i když web odeberete z monitoringu.',
            ],
        ];
    }

    /** @return array<string, mixed> kontext pro společné metody */
    private function projectContext(int $id): array
    {
        $project = $this->kernel->projects()->find($id) ?? throw HttpException::notFound('Projekt neexistuje.');

        return [
            'projectId' => (int) $project['id'],
            'siteId' => null,
            'base' => 'projekty/' . (int) $project['id'] . '/pristupy',
            'auditSiteId' => null,
            'auditName' => '',
            'view' => [
                'project' => $project,
                'headerPartial' => 'partials/project-header',
                'pageTitle' => (string) $project['name'],
                'ownerNote' => 'Přístupy se smažou jen se smazáním projektu. U webů projektu je najdete na záložce Přístupy.',
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    private function audit(array $context, string $description): void
    {
        $this->kernel->audit()->record(
            $context['auditSiteId'],
            $context['auditName'],
            $context['auditSiteId'] !== null ? AuditLog::ACTION_SITE_EDIT : AuditLog::ACTION_PROJECT,
            true,
            $description . ($context['auditSiteId'] === null ? ' (projekt ' . $context['view']['pageTitle'] . ')' : ''),
        );
    }

    /**
     * Formulář přidání i úpravy — pole podle druhu (`Credentials::KINDS`).
     *
     * @param array<string, mixed>      $context
     * @param array<string, mixed>|null $credential null = nový
     * @param array<string, mixed>      $values
     * @param array<string, string>     $errors
     */
    private function form(array $context, string $kind, ?array $credential, array $values, array $errors, int $status = 200): Response
    {
        $base = $context['base'];
        $protocols = [];

        foreach (Credentials::PROTOCOLS as $code => $protocol) {
            $protocols[$code] = $protocol['label'];
        }

        $isEmail = $kind === 'email';

        return $this->view('vault/form', $context['view'] + [
            'kind' => $kind,
            'kindLabel' => Credentials::KINDS[$kind]['label'],
            'fields' => array_fill_keys(Credentials::KINDS[$kind]['fields'], true),
            'labelLabel' => $isEmail ? 'Kde schránka běží' : 'Název',
            'labelPlaceholder' => $isEmail ? 'Wedos · Google Workspace · Microsoft 365' : 'Google Analytics',
            'labelRequired' => $kind === 'other',
            'usernameLabel' => $isEmail ? 'E-mailová adresa' : 'Uživatel',
            'loginFirst' => $isEmail,
            'urlLabel' => match ($kind) {
                'hosting' => 'Odkaz na administraci',
                'database' => 'Odkaz na phpMyAdmin',
                'email' => 'Webmail',
                'registrar' => 'Odkaz na administraci registrátora',
                default => 'Adresa',
            },
            'hostLabel' => match ($kind) {
                'database' => 'Server databáze',
                'email' => 'Server pošty (IMAP / SMTP)',
                default => 'Server',
            },
            'hostPlaceholder' => match ($kind) {
                'database' => 'localhost',
                'email' => 'imap.wedos.net',
                default => 'ftp.example.cz',
            },
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
        $port = $credential['port'] ?? (Credentials::PROTOCOLS[$protocol]['port'] ?? null);

        // Druhý řádek pod názvem: u FTP protokol, u databáze jméno databáze,
        // u e-mailu kde schránka běží.
        $detail = match ($kind) {
            'ftp' => strtoupper($protocol !== '' ? $protocol : 'ftp'),
            'database' => (string) $credential['database_name'] !== '' ? 'databáze ' . $credential['database_name'] : '',
            'email' => (string) $credential['label'],
            default => '',
        };

        return [
            'title' => self::title($kind, $credential),
            'icon' => Credentials::KINDS[$kind]['icon'],
            // Pod názvem: detail a poznámka; celá poznámka je v bublině.
            'subline' => implode(' · ', array_filter([$detail, (string) $credential['note']], static fn (string $part): bool => $part !== '')),
            'note' => (string) $credential['note'],
            'server' => $kind === 'ftp' || (in_array($kind, ['database', 'email'], true) && (string) $credential['url'] === '')
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

        if ($kind === 'email' && filter_var($values['username'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['username'] = 'Zadejte adresu schránky, třeba info@pekarnanovak.cz.';
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
     * Název přístupu ve výpisu a v historii: u e-mailu adresa, jinak vlastní
     * popisek, nebo druh.
     *
     * @param array<string, mixed> $values
     */
    private static function title(string $kind, array $values): string
    {
        if ($kind === 'email' && trim((string) ($values['username'] ?? '')) !== '') {
            return trim((string) $values['username']);
        }

        $label = trim((string) ($values['label'] ?? ''));

        return $label !== '' ? $label : Credentials::KINDS[$kind]['label'];
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
        if (!isset(Credentials::KINDS[$kind])) {
            throw HttpException::notFound('Neznámý druh přístupu.');
        }

        return $kind;
    }

    /** @return array<string, mixed> */
    private function credentialOr404(int $projectId, int $id): array
    {
        return $this->kernel->credentials()->find($projectId, $id)
            ?? throw HttpException::notFound('Přístup neexistuje, nebo patří jinému projektu.');
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
}
