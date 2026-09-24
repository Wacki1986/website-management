<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Security\Secrets;
use SensitiveParameter;

/**
 * Dvoufázové přihlášení účtu: tajemství pro aplikaci v telefonu a záložní kódy.
 *
 * Proč vůbec: správa drží API klíče webů a trezor přístupů (FTP, hosting,
 * databáze). Samotné heslo pak nestačí — kdo ho odkoukne nebo vyláká,
 * nemá bez telefonu nic.
 *
 * Tajemství se ukládá šifrovaně přes `Secrets` (klíč v `config/env.php`),
 * stejně jako API klíče webů — záloha databáze ho nenese v čitelné podobě.
 * Záložní kódy jsou jen jako hash: jsou jednorázové a náhodné, zpátky je
 * číst nikdo nepotřebuje.
 */
final class TwoFactor
{
    /** Pod tímhle jménem se účet objeví v aplikaci Authenticator. */
    public const ISSUER = 'Správa webů MEDIAGRAFIK';

    public const RECOVERY_COUNT = 10;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Secrets $secrets,
    ) {
    }

    /** @param array<string, mixed> $user */
    public function isEnabled(array $user): bool
    {
        return ($user['totp_enabled_at'] ?? null) !== null && (string) ($user['totp_secret'] ?? '') !== '';
    }

    /** Dá se vůbec zapnout? Bez `app_key` by nebylo čím tajemství zašifrovat. */
    public function isAvailable(): bool
    {
        return $this->secrets->isReady();
    }

    /**
     * Zapne dvoufázové přihlášení — až poté, co účet opíše platný kód.
     *
     * Kód se ověřuje tady, ne v controlleru: tajemství, které aplikace
     * v telefonu nemá správně, se nesmí uložit — účet by se zamkl.
     *
     * @return array<int, string>|null nové záložní kódy (ukázat jednou), null = kód nesedí
     */
    public function enable(int $userId, #[SensitiveParameter] string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, self::normalize($code), time());

        if ($step === null) {
            return null;
        }

        $this->users->update($userId, [
            'totp_secret' => $this->secrets->encrypt($secret),
            'totp_enabled_at' => date('Y-m-d H:i:s'),
            'totp_last_step' => $step,
        ]);

        return $this->regenerateRecoveryCodes($userId);
    }

    public function disable(int $userId): void
    {
        $this->users->update($userId, [
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_step' => null,
            'recovery_codes' => null,
        ]);
    }

    /**
     * Ověří druhý krok: kód z aplikace, nebo jeden ze záložních kódů.
     *
     * Šest číslic = kód z aplikace, cokoli jiného se zkusí jako záložní kód.
     * Použitý kód se zapamatuje (aplikace: číslo kroku, záložní: smaže se),
     * takže odkoukaný kód podruhé neprojde.
     *
     * @param array<string, mixed> $user řádek z `users`
     */
    public function verify(array $user, string $code): bool
    {
        if (!$this->isEnabled($user)) {
            return false;
        }

        $code = self::normalize($code);

        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $code) === 1) {
            return $this->verifyTotp($user, $code);
        }

        return $this->useRecoveryCode($user, $code);
    }

    /**
     * Nová sada záložních kódů — stará tím přestane platit celá.
     *
     * @return array<int, string> kódy v čitelné podobě („k7m2p-x9q4w"); ukázat jednou
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            // Bez znaků, které se pletou (0/o, 1/l/i) — kód se opisuje z papíru.
            $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
            $code = '';

            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        $this->users->update($userId, [
            'recovery_codes' => json_encode(array_map(self::hashRecovery(...), $codes)),
        ]);

        return $codes;
    }

    /** @param array<string, mixed> $user */
    public function remainingRecoveryCodes(array $user): int
    {
        return count($this->recoveryHashes($user));
    }

    /** Tajemství účtu v čitelné podobě (jen pro ověření kódu). */
    private function secret(array $user): ?string
    {
        return $this->secrets->decrypt((string) ($user['totp_secret'] ?? ''));
    }

    /** @param array<string, mixed> $user */
    private function verifyTotp(array $user, string $code): bool
    {
        $secret = $this->secret($user);

        if ($secret === null) {
            return false;
        }

        $lastStep = ($user['totp_last_step'] ?? null) !== null ? (int) $user['totp_last_step'] : null;
        $step = Totp::verify($secret, $code, time(), $lastStep);

        if ($step === null) {
            return false;
        }

        $this->users->update((int) $user['id'], ['totp_last_step' => $step]);

        return true;
    }

    /** @param array<string, mixed> $user */
    private function useRecoveryCode(array $user, string $code): bool
    {
        $hashes = $this->recoveryHashes($user);
        $given = self::hashRecovery($code);

        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $given)) {
                unset($hashes[$index]);
                $this->users->update((int) $user['id'], ['recovery_codes' => json_encode(array_values($hashes))]);

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, string>
     */
    private function recoveryHashes(array $user): array
    {
        $decoded = json_decode((string) ($user['recovery_codes'] ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Hash záložního kódu. Stačí SHA-256 bez soli: kód je náhodný
     * (50 bitů), ne heslo vymyšlené člověkem, takže slovník na něj není.
     * Pomlčka a velikost písmen se nepočítají — opisuje se z papíru.
     */
    private static function hashRecovery(string $code): string
    {
        return hash('sha256', str_replace('-', '', self::normalize($code)));
    }

    /** Mezery (aplikace ukazují „123 456") a velká písmena pryč. */
    private static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/\s+/', '', $code));
    }
}
