<?php

declare(strict_types=1);

namespace App\Core\Sites;

/**
 * Jak čerstvý je obsah webu — jedno měřítko pro záložku Obsah i pro
 * sekci „Obsah webu" v klientském reportu, ať obojí svítí stejně.
 *
 * Do měsíce od posledního příspěvku je web „živý", do tří měsíců si
 * zaslouží připomenutí, pak už působí opuštěně.
 */
final class ContentFreshness
{
    public const WARNING_DAYS = 30;
    public const ERROR_DAYS = 90;

    /** @return string ok | warning | error | muted (žádný obsah) */
    public static function tone(?int $daysAgo): string
    {
        return match (true) {
            $daysAgo === null => 'muted',
            $daysAgo < self::WARNING_DAYS => 'ok',
            $daysAgo < self::ERROR_DAYS => 'warning',
            default => 'error',
        };
    }

    /** „dnes" / „včera" / „před 45 dny" */
    public static function ageLabel(int $daysAgo): string
    {
        return match ($daysAgo) {
            0 => 'dnes',
            1 => 'včera',
            default => 'před ' . $daysAgo . ' dny',
        };
    }

    /**
     * Název z WordPressu jako prostý text. `get_the_title()` ho posílá už
     * připravený pro HTML (`&#8222;` místo „, `&nbsp;`) — bez dekódování by
     * se při escapování vypsaly entity doslova.
     */
    public static function title(string $title): string
    {
        return trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Dny od data publikace do dne `$today` (Y-m-d); budoucí datum = 0. */
    public static function daysSince(string $date, string $today): int
    {
        return max(0, (int) floor((strtotime($today) - strtotime(substr($date, 0, 10))) / 86400));
    }
}
