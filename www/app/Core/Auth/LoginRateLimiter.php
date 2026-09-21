<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Security\RateLimiter;

/**
 * Ochrana proti hádání hesel.
 *
 * Limit je dvojí: na přihlašovací jméno (útok na konkrétní účet) a na IP
 * (plošné zkoušení). Odpověď při zablokování je vždy stejná jako při špatném
 * heslu, aby se z ní nedalo poznat, že účet existuje.
 *
 * Počítání samo obstarává obecný `Security\RateLimiter` — od chvíle, kdy
 * totéž potřeboval i veřejný endpoint pro poptávky. Tahle třída zůstala,
 * protože nese **pravidla přihlašování** (kolik pokusů, jak dlouhé okno,
 * dvojí klíč); kdyby se rozpustila do volajícího, skončila by ta čísla
 * roztroušená po auth vrstvě.
 */
final class LoginRateLimiter
{
    public const BUCKET_USERNAME = 'login.username';
    public const BUCKET_IP = 'login.ip';

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxPerUsername = 5,
        private readonly int $maxPerIp = 20,
        private readonly int $windowMinutes = 15,
    ) {
    }

    public function tooManyAttempts(string $username, string $ip): bool
    {
        return $this->limiter->tooMany(self::BUCKET_USERNAME, $username, $this->maxPerUsername, $this->windowMinutes)
            || $this->limiter->tooMany(self::BUCKET_IP, $ip, $this->maxPerIp, $this->windowMinutes);
    }

    public function record(string $username, string $ip, bool $success): void
    {
        $this->limiter->record(self::BUCKET_USERNAME, $username, $success);
        $this->limiter->record(self::BUCKET_IP, $ip, $success);
    }

    /** Po úspěšném přihlášení se počítadlo pro daný účet vynuluje. */
    public function clear(string $username): void
    {
        $this->limiter->clear(self::BUCKET_USERNAME, $username);
    }

    /**
     * Úklid starých pokusů — správa nemá cron, volá se po úspěšném přihlášení.
     * (Doplněk oproti klientské aplikaci, kde úklid dělá /system/cron.)
     */
    public function purge(): void
    {
        $this->limiter->purge();
    }
}
