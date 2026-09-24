<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Db\Connection;

/**
 * Účty správy.
 *
 * Původně navrženo na jednoho uživatele — repozitář přesto pracoval
 * s tabulkou standardně (find/findByUsername), protože „jeden uživatel" byl
 * provozní rozhodnutí, ne omezení schématu. Nově se to využívá:
 * účtů může být víc (`all()`, `findByEmail()` kvůli obnově zapomenutého
 * hesla, `findByLogin()` kvůli přihlášení e-mailem).
 */
final class UserRepository
{
    /**
     * Role je jen štítek v tabulce uživatelů — všichni mohou vše (rozhodnutí
     * z návrhu: malé studio, nikdo nikomu nic neschovává). Slovník je
     * uzavřený, aby se dal vypsat do výběru.
     */
    public const ROLES = [
        'admin' => 'Správce',
        'technician' => 'Technik',
        'accounts' => 'Účty a reporty',
    ];

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? self::ROLES['admin'];
    }

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE username = :username', ['username' => $username]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        return $this->db->selectOne('SELECT * FROM users WHERE email = :email', ['email' => $email]);
    }

    /**
     * Účet podle toho, co člověk napsal do přihlašovacího formuláře — jména,
     * nebo e-mailu.
     *
     * Obojí proto, že obnova hesla se vyžaduje **e-mailem**: kdo si heslo
     * nastavil odkazem z e-mailu, má ho ve správci hesel uložený i jako
     * přihlašovací údaj a pak ho píše do přihlášení. Dřív ho správa odmítla
     * hláškou „Nesprávné přihlašovací jméno nebo heslo" i se správným heslem
     * (nález z provozu, 3. 9. 2026).
     *
     * Jméno má přednost: kdyby se něčí přihlašovací jméno shodovalo s cizím
     * e-mailem, vyhrává vlastník jména — je to primární identifikátor účtu.
     *
     * @return array<string, mixed>|null
     */
    public function findByLogin(string $login): ?array
    {
        // E-maily se ukládají malými písmeny (`SettingsController::addUser()`),
        // psát adresu do přihlášení velkými nesmí vadit.
        return $this->findByUsername($login) ?? $this->findByEmail(mb_strtolower($login));
    }

    /**
     * Patří přihlašovací jméno nebo e-mail už jinému účtu?
     *
     * Hledá se v obou sloupcích naráz — přihlásit se dá jménem i e-mailem
     * (`findByLogin()`), takže cizí e-mail nesmí být něčím jménem a naopak.
     */
    public function isLoginTaken(string $login, int $exceptId = 0): bool
    {
        if ($login === '') {
            return false;
        }

        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE (username = :username OR email = :email) AND id <> :id',
            ['username' => $login, 'email' => $login, 'id' => $exceptId],
        ) > 0;
    }

    /** Pravidla přihlašovacího jména — null, když je v pořádku. */
    public static function usernameError(string $username): ?string
    {
        return preg_match('/^[a-z0-9._-]{3,50}$/', $username) === 1
            ? null
            : 'Přihlašovací jméno: 3–50 znaků, jen malá písmena bez diakritiky, číslice, tečka, pomlčka a podtržítko.';
    }

    /**
     * Smí účet upravovat, pozastavit nebo odpárovat jiný účet?
     *
     * Všichni si jsou rovni s jedinou výjimkou: na zakládající účet
     * (`firstUserId()`) nesmí sahat nikdo jiný než on sám — jinak by mu
     * druhý účet mohl změnit e-mail pro obnovu hesla a převzít ho.
     *
     * @param array<string, mixed> $actor přihlášený
     */
    public function canManage(array $actor, int $targetId): bool
    {
        $ownerId = $this->firstUserId();

        return $targetId !== $ownerId || (int) $actor['id'] === $ownerId;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users');
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM users ORDER BY username');
    }

    public function create(string $username, string $passwordHash, string $email = ''): int
    {
        return $this->db->insert('users', [
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password_hash' => $passwordHash,
            'is_active' => 1,
            'theme' => 'auto',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('users', $data + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * ID zakládajícího účtu — kdo správu jako první rozjel (nejnižší `id`).
     *
     * Nejde o roli uloženou v databázi, jen odvozený fakt: první účet je
     * jediný, o kterém je jisté, že má přístup k hostingu/FTP i bez
     * správy samotné, takže se nemá dát pozastavit nikým jiným
     * (`SettingsController::suspendUser()`) — jinak by ho mohl zamknout
     * druhý přidaný účet.
     */
    public function firstUserId(): ?int
    {
        $id = $this->db->scalar('SELECT MIN(id) FROM users');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Jak se má o účtu mluvit na obrazovce — jméno, když je vyplněné,
     * jinak přihlašovací jméno. Přihlašovací jméno zůstává vždycky (login,
     * CLI nástroje); jméno je jen kosmetika navrch.
     *
     * @param array<string, mixed> $user
     */
    public static function displayName(array $user): string
    {
        $name = trim((string) ($user['name'] ?? ''));

        return $name !== '' ? $name : (string) $user['username'];
    }

    /**
     * Iniciály do kolečka bez fotky — z jména („Jan Vašák" → JV), bez něj
     * první dvě písmena přihlašovacího jména.
     *
     * @param array<string, mixed> $user
     */
    public static function initials(array $user): string
    {
        $words = preg_split('/\s+/', trim((string) ($user['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1);

        if ($initials === '') {
            $initials = mb_substr((string) ($user['username'] ?? '?'), 0, 2);
        }

        return mb_strtoupper($initials);
    }
}
