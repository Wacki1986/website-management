<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Totp;
use App\Core\Auth\TwoFactor;
use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Spárování telefonu pro dvoufázové přihlášení a záložní kódy.
 *
 * Postup zapnutí: stránka ukáže QR kód s novým tajemstvím, účet ho
 * naskenuje aplikací Authenticator a opíše z ní první kód. Teprve ten
 * potvrdí, že telefon tajemství má správně — do té doby leží jen v session
 * a účet se nemůže zamknout nedokončeným párováním.
 *
 * Dvoufázové přihlášení je povinné (vynucuje ho `Kernel::handle()`).
 * Nový telefon a nové záložní kódy chtějí aktuální kód: kdo si jen sedne
 * k odemčenému počítači, spárování si nepřevezme.
 */
final class TwoFactorController extends Controller
{
    /** Tajemství rozpracovaného párování (než ho potvrdí první kód). */
    private const SESSION_SETUP = 'two_factor_setup';

    /** Čerstvé záložní kódy — ukážou se jednou, pak z session zmizí. */
    private const SESSION_CODES = 'two_factor_codes';

    public function setup(): Response
    {
        $user = $this->user();

        if ($this->kernel->twoFactor()->isEnabled($user)) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Telefon už máte spárovaný.');
        }

        return $this->setupView($user, []);
    }

    public function enable(): Response
    {
        $user = $this->user();
        $secret = (string) ($_SESSION[self::SESSION_SETUP] ?? '');

        // Session mezitím vypršela — QR kód na stránce už neplatí.
        if ($secret === '') {
            return $this->redirectWithFlash('nastaveni/dvoufazove', 'Párování vypršelo. Naskenujte prosím nový QR kód.', 'error');
        }

        $codes = $this->kernel->twoFactor()->enable((int) $user['id'], $secret, $this->request()->string('code'));

        if ($codes === null) {
            return $this->setupView($user, ['code' => 'Kód nesouhlasí. Zkontrolujte, že je v telefonu správný čas, a opište aktuální kód.'], 422);
        }

        unset($_SESSION[self::SESSION_SETUP]);
        // Po spárování (nový účet, nový telefon) se pokračuje do aplikace.
        $_SESSION[self::SESSION_CODES] = ['codes' => $codes, 'next' => '/'];
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Spárován telefon pro dvoufázové přihlášení');

        return $this->redirectWithFlash('nastaveni/dvoufazove/kody', 'Telefon je spárovaný.');
    }

    /** Záložní kódy hned po vytvoření — jen jednou, obnovení stránky je už neukáže. */
    public function codes(): Response
    {
        $pending = $_SESSION[self::SESSION_CODES] ?? null;
        unset($_SESSION[self::SESSION_CODES]);
        $codes = is_array($pending) ? (array) ($pending['codes'] ?? []) : [];

        if ($codes === []) {
            return $this->redirect('nastaveni/uzivatele');
        }

        return $this->view('settings/dvoufazove-kody', [
            'title' => 'Nastavení',
            'activeTab' => 'uzivatele',
            'codes' => $codes,
            'codesText' => implode("\n", $codes),
            'nextUrl' => get_url((string) ($pending['next'] ?? 'nastaveni/uzivatele')),
        ]);
    }

    public function regenerateCodes(): Response
    {
        $user = $this->user();

        if (!$this->verifyCode($user)) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Kód nesouhlasí — nové záložní kódy se nevytvořily.', 'error');
        }

        $_SESSION[self::SESSION_CODES] = [
            'codes' => $this->kernel->twoFactor()->regenerateRecoveryCodes((int) $user['id']),
            'next' => 'nastaveni/uzivatele',
        ];
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Nové záložní kódy dvoufázového přihlášení');

        return $this->redirectWithFlash('nastaveni/dvoufazove/kody', 'Nové záložní kódy jsou vytvořené, staré přestaly platit.');
    }

    /**
     * Nový telefon: staré spárování se zruší a hned se páruje znovu.
     *
     * Vypnout dvoufázové přihlášení úplně nejde — je povinné. Kernel účet
     * bez spárování pustí jen na stránku párování.
     */
    public function disable(): Response
    {
        $user = $this->user();

        if (!$this->verifyCode($user)) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Kód nesouhlasí — telefon zůstává spárovaný.', 'error');
        }

        $this->kernel->twoFactor()->disable((int) $user['id']);
        unset($_SESSION[self::SESSION_SETUP]);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Zrušeno spárování telefonu (párování nového)');

        return $this->redirectWithFlash('nastaveni/dvoufazove', 'Staré spárování je zrušené. Naskenujte QR kód novým telefonem.');
    }

    /**
     * Zrušení spárování u kolegy, který ztratil telefon i záložní kódy.
     * Při příštím načtení stránky si telefon spáruje znovu.
     *
     * Zakládající účet takhle odpárovat nejde (stejně jako ho nejde
     * pozastavit) — jinak by druhý účet mohl vlastníkovi shodit ochranu.
     * Vlastník má na ztrátu telefonu záložní kódy a `zalozeni-spravce.php`.
     */
    public function disableFor(string $id): Response
    {
        $current = $this->user();
        $users = $this->kernel->users();

        if ((int) $current['id'] === (int) $id) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Svůj telefon přespárujete v kartě Dvoufázové přihlášení, s kódem z aplikace.', 'error');
        }

        if (!$users->canManage($current, (int) $id)) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Zakládajícímu účtu nejde spárování zrušit z jiného účtu.', 'error');
        }

        $target = $users->find((int) $id) ?? throw HttpException::notFound('Účet neexistuje.');

        $this->kernel->twoFactor()->disable((int) $id);
        $this->kernel->rememberMe()->forgetAll((int) $id);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Zrušeno spárování telefonu účtu ' . UserRepository::displayName($target));

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Účet ' . UserRepository::displayName($target) . ' má zrušené spárování — po přihlášení si telefon spáruje znovu.');
    }

    /**
     * Stránka párování. Tajemství vzniká jednou a drží se v session, aby
     * obnovení stránky nezměnilo QR kód, který už je v telefonu naskenovaný.
     *
     * @param array<string, mixed>  $user
     * @param array<string, string> $errors
     */
    private function setupView(array $user, array $errors, int $status = 200): Response
    {
        $available = $this->kernel->twoFactor()->isAvailable();
        $secret = $available ? ($_SESSION[self::SESSION_SETUP] ??= Totp::generateSecret()) : '';
        $account = (string) ($user['email'] ?? '') !== '' ? (string) $user['email'] : (string) $user['username'];

        return $this->view('settings/dvoufazove', [
            'title' => 'Spárování telefonu',
            'available' => $available,
            'otpauthUri' => $secret !== '' ? Totp::provisioningUri($secret, $account, TwoFactor::ISSUER) : '',
            'secretFormatted' => Totp::formatSecret($secret),
            'account' => $account,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Kód z formuláře vlastního účtu — se stejným počítadlem pokusů jako
     * při přihlášení, ať se nedá hádat z odemčeného počítače.
     *
     * @param array<string, mixed> $user
     */
    private function verifyCode(array $user): bool
    {
        $limiter = $this->kernel->loginRateLimiter();
        $username = (string) $user['username'];

        if ($limiter->tooManyCodeAttempts($username)) {
            throw HttpException::tooManyRequests('Příliš mnoho špatných kódů. Zkuste to za čtvrt hodiny.');
        }

        $ok = $this->kernel->twoFactor()->verify($user, $this->request()->string('code'));
        $limiter->recordCode($username, $ok);

        return $ok;
    }

    /** @return array<string, mixed> */
    private function user(): array
    {
        return $this->kernel->auth()->current() ?? throw HttpException::unauthorized();
    }
}
