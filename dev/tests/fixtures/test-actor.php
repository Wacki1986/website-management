<?php

declare(strict_types=1);

/**
 * Přihlášený `Auth` pro testy služeb, které teď zapisují autora do auditu.
 *
 * `AuditLog` zjišťuje autora z `Auth::current()` (session) — testy služeb
 * (Suspend, Queue, Decommission, Provision) samy o přihlášení nikdy
 * nepečovaly, protože audit dřív žádného uživatele nepotřeboval. Tahle
 * fixture přihlásí jednoho testovacího uživatele, ať se dá ověřit, že
 * `audit_log.user_id`/`user_name` sedí.
 */

use App\Core\Auth\Auth;
use App\Core\Auth\LoginRateLimiter;
use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\RememberMe;
use App\Core\Auth\UserRepository;
use App\Core\Db\Connection;
use App\Core\Http\Csrf;
use App\Core\Http\Request;
use App\Core\Security\RateLimiter;

const TEST_ACTOR_USERNAME = 'tester';
const TEST_ACTOR_PASSWORD = 'testovaci-heslo-actor-123';

function testActorAuth(Connection $db, string $username = TEST_ACTOR_USERNAME): Auth
{
    $_SESSION = [];

    $users = new UserRepository($db);
    $users->create($username, PasswordPolicy::hash(TEST_ACTOR_PASSWORD));

    $auth = new Auth($users, new RememberMe($db), new LoginRateLimiter(new RateLimiter($db)), new Csrf());
    $auth->login(
        new Request(method: 'POST', path: '/prihlaseni', server: ['REMOTE_ADDR' => '10.0.0.9']),
        $username,
        TEST_ACTOR_PASSWORD,
    );

    return $auth;
}
