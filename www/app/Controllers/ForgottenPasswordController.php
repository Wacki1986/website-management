<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth\PasswordPolicy;
use App\Core\Http\Controller;
use App\Core\Http\Response;
use App\Core\Kernel;

/**
 * Zapomenuté heslo — obnova e-mailem (nahrazuje/doplňuje CLI cestu
 * z provozní dokumentace §5, která zůstává jako záloha bez e-mailu).
 *
 * Odpověď na žádost je vždy stejná, ať e-mail v evidenci je nebo není —
 * jinak by formulář prozrazoval, které adresy jsou zaregistrované (stejný
 * princip jako u přihlášení, `Auth::login()`).
 */
final class ForgottenPasswordController extends Controller
{
    public function show(): Response
    {
        return $this->view('auth/zapomenute-heslo', [
            'title' => 'Zapomenuté heslo',
        ]);
    }

    public function send(): Response
    {
        $request = $this->request();
        $email = mb_strtolower(trim($request->string('email')));
        $ip = $request->ip();
        $generic = 'Pokud e-mail v evidenci existuje, poslali jsme na něj odkaz pro obnovu hesla. Zkontrolujte i spam.';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('zapomenute-heslo', $generic);
        }

        $limiter = $this->kernel->limiter();

        if ($limiter->tooMany('password_reset.email', $email, 3, 60) || $limiter->tooMany('password_reset.ip', $ip, 10, 60)) {
            return $this->redirectWithFlash('zapomenute-heslo', $generic);
        }

        $limiter->record('password_reset.email', $email);
        $limiter->record('password_reset.ip', $ip);

        $user = $this->kernel->users()->findByEmail($email);

        if ($user !== null && (int) $user['is_active'] === 1) {
            $this->sendResetEmail((int) $user['id'], $email);
        }

        $this->kernel->passwordReset()->purgeExpired();

        return $this->redirectWithFlash('zapomenute-heslo', $generic);
    }

    public function showReset(string $token): Response
    {
        return $this->view('auth/obnova-hesla', [
            'title' => 'Nové heslo',
            'token' => $token,
            'valid' => $this->kernel->passwordReset()->verify($token) !== null,
            'errors' => [],
        ]);
    }

    public function reset(string $token): Response
    {
        $request = $this->request();
        $error = PasswordPolicy::validate(
            (string) $request->input('password', ''),
            (string) $request->input('password_confirm', ''),
        );

        if ($error !== null) {
            return $this->view('auth/obnova-hesla', [
                'title' => 'Nové heslo',
                'token' => $token,
                'valid' => true,
                'errors' => ['password' => $error],
            ], 422);
        }

        $userId = $this->kernel->passwordReset()->consume($token);

        if ($userId === null) {
            return $this->view('auth/obnova-hesla', [
                'title' => 'Nové heslo',
                'token' => $token,
                'valid' => false,
                'errors' => [],
            ], 410);
        }

        $this->kernel->users()->update($userId, [
            'password_hash' => PasswordPolicy::hash((string) $request->input('password', '')),
        ]);

        // Stejné chování jako běžná změna hesla v Nastavení: obnova odhlásí
        // všechny relace včetně trvalého přihlášení.
        // Kdo si nastavil heslo z pozvánky, pozvánku přijal.
        $this->kernel->users()->update($userId, ['invited_at' => null]);
        $this->kernel->passwordReset()->invalidateFor($userId);
        $this->kernel->rememberMe()->forgetAll($userId);

        return $this->redirectWithFlash('prihlaseni', 'Heslo je změněné, přihlaste se.');
    }

    private function sendResetEmail(int $userId, string $email): void
    {
        $token = $this->kernel->passwordReset()->issue($userId);
        $url = $this->kernel->appUrl('obnova-hesla/' . $token);

        // Strukturovaná šablona (návrh „E-maily v2", 1b) — HTML i textová
        // varianta vznikají z téže stavebnice.
        $this->kernel->mailer()->sendMessage(
            $email,
            'Obnova hesla — ' . Kernel::APP_NAME,
            \App\Core\Notifications\EmailMessage::make('Obnova hesla')
                ->paragraph('Někdo (doufáme, že vy) požádal o obnovu hesla ke správě instancí.')
                ->button('Nastavit nové heslo', $url)
                ->smallprint('Odkaz platí hodinu. Pokud jste o obnovu nežádali, nic nedělejte — heslo zůstává beze změny.'),
        );
    }
}
