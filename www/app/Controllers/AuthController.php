<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Přihlášení, odhlášení a drobnosti účtu (motiv).
 *
 * Jméno (nebo e-mail) + heslo; zapomenuté heslo řeší ForgottenPasswordController.
 */
final class AuthController extends Controller
{
    public function show(): Response
    {
        if ($this->kernel->auth()->check($this->request())) {
            return $this->redirect('/');
        }

        return $this->view('auth/login', [
            'title' => 'Přihlášení',
            'username' => '',
            'errors' => [],
        ]);
    }

    public function login(): Response
    {
        $request = $this->request();
        $username = $request->string('username');

        try {
            $this->kernel->auth()->login($request, $username, $request->string('password'), $request->bool('remember'));
        } catch (HttpException $e) {
            $this->rethrowIfForbidden($e);

            /**
             * Chyba se vypisuje jednou, pruhem v kartě; pole se neoznačují —
             * přihlášení neprozrazuje, jestli neseděl login, nebo heslo.
             * Heslo se do formuláře nevrací nikdy.
             */
            return $this->view('auth/login', [
                'title' => 'Přihlášení',
                'username' => $username,
                'errors' => $e->errors() !== [] ? $e->errors() : ['_' => $e->getMessage()],
            ], $e->status() === 429 ? 429 : 422);
        }

        return $this->redirect('/');
    }

    public function logout(): Response
    {
        $this->kernel->auth()->logout($this->request());

        return $this->redirect('prihlaseni');
    }

    /**
     * Volba motivu (auto / světlý / tmavý).
     *
     * Ukládá se k účtu, ne do cookie — správa se otevírá i z jiného počítače.
     * Bez JavaScriptu je to obyčejný POST formuláře z nabídky účtu; skript
     * (assets/js/modules/theme.js) totéž pošle fetchem a ušetří překreslení.
     */
    public function theme(): Response
    {
        $request = $this->request();
        $theme = $request->string('theme');

        if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
            throw HttpException::validation('Neznámý motiv.');
        }

        $user = $this->kernel->auth()->current();

        if ($user !== null) {
            $this->kernel->users()->update((int) $user['id'], ['theme' => $theme]);
        }

        if ($request->wantsJson()) {
            return $this->json(['status' => 'success']);
        }

        return $this->redirect('/');
    }
}
