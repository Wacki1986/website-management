<?php

declare(strict_types=1);

namespace App\Core\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Počítání termínů servisu — čistá logika bez databáze.
 *
 * Druhy servisu (návrh: Malý / Střední / Velký) a opakování (měsíčně,
 * čtvrtletně, pololetně, jednorázově). Termíny se odvíjejí od „prvního
 * servisu"; po zapsaném servisu se další termín posune na nejbližší
 * budoucí podle rytmu, ne od data zápisu (servis o týden dřív neposune
 * celý plán).
 */
final class ServiceSchedule
{
    /** @var array<string, array{label: string, note: string, icon: string}> */
    public const KINDS = [
        'small' => ['label' => 'Malý servis', 'note' => 'aktualizace WordPressu a pluginů, kontrola záloh a formulářů', 'icon' => 'refresh'],
        'medium' => ['label' => 'Střední servis', 'note' => 'malý servis + kontrola bezpečnosti, čištění databáze a médií', 'icon' => 'shield'],
        'large' => ['label' => 'Velký servis', 'note' => 'střední servis + optimalizace rychlosti, revize obsahu a SEO', 'icon' => 'clock'],
    ];

    /** @var array<string, array{label: string, months: int}> months 0 = jednorázově */
    public const FREQUENCIES = [
        'monthly' => ['label' => 'Měsíčně', 'months' => 1],
        'quarterly' => ['label' => 'Čtvrtletně', 'months' => 3],
        'halfyearly' => ['label' => 'Pololetně', 'months' => 6],
        'once' => ['label' => 'Jednorázově', 'months' => 0],
    ];

    /**
     * Nejbližší termín ≥ `$after` (včetně) v rytmu od prvního data.
     * Jednorázový plán vrací první datum, když ještě nenastalo, jinak null.
     */
    public static function next(string $firstDate, string $frequency, string $after): ?string
    {
        $months = self::FREQUENCIES[$frequency]['months'] ?? 1;
        $first = new DateTimeImmutable($firstDate);
        $from = new DateTimeImmutable($after);

        if ($months === 0) {
            return $first >= $from ? $first->format('Y-m-d') : null;
        }

        $candidate = $first;
        $i = 0;

        // Přičítání po měsících od prvního data (ne od minulého termínu), aby
        // 31. 1. → 28. 2. → 31. 3. nesklouzlo natrvalo na 28.
        while ($candidate < $from) {
            $i++;
            $candidate = self::addMonths($first, $months * $i);
        }

        return $candidate->format('Y-m-d');
    }

    /**
     * Několik nadcházejících termínů od `$today` (pilulky v plánu).
     *
     * @return array<int, string>
     */
    public static function upcoming(string $firstDate, string $frequency, string $today, int $count = 3): array
    {
        $months = self::FREQUENCIES[$frequency]['months'] ?? 1;
        $first = self::next($firstDate, $frequency, $today);

        if ($first === null) {
            return [];
        }

        if ($months === 0) {
            return [$first];
        }

        $dates = [$first];
        $base = new DateTimeImmutable($firstDate);
        $index = self::indexOf($firstDate, $months, $first);

        for ($n = 1; $n < $count; $n++) {
            $dates[] = self::addMonths($base, $months * ($index + $n))->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Po zapsaném servisu: další termín = nejbližší v rytmu PO dni provedení
     * a zároveň PO právě plněném termínu (servis o týden dřív plní termín,
     * který teprve přijde, a neposouvá rytmus). Jednorázový plán končí (null).
     */
    public static function afterPerformed(string $firstDate, string $frequency, string $performedOn, ?string $currentNext = null): ?string
    {
        $months = self::FREQUENCIES[$frequency]['months'] ?? 1;

        if ($months === 0) {
            return null;
        }

        $fulfilled = $currentNext !== null && $currentNext > $performedOn ? $currentNext : $performedOn;
        $dayAfter = (new DateTimeImmutable($fulfilled))->add(new DateInterval('P1D'))->format('Y-m-d');

        return self::next($firstDate, $frequency, $dayAfter);
    }

    /** Posun termínu o týden (tlačítko „Posunout o týden"). */
    public static function postpone(string $date, int $days = 7): string
    {
        return (new DateTimeImmutable($date))->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
    }

    /** Kolik dní do termínu (záporné = po termínu). */
    public static function daysUntil(string $date, string $today): int
    {
        return (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($date))->format('%r%a');
    }

    /** „za 12 dní" / „zítra" / „dnes" / „po termínu 5 d" — popisek k termínu. */
    public static function countdown(string $date, string $today): string
    {
        $days = self::daysUntil($date, $today);

        return match (true) {
            $days === 0 => 'dnes',
            $days === 1 => 'zítra',
            $days > 1 => 'za ' . get_count($days, 'den', 'dny', 'dní'),
            $days === -1 => 'po termínu 1 d',
            default => 'po termínu ' . abs($days) . ' d',
        };
    }

    /**
     * Buňka „Servis" v seznamu webů, u klienta a odznak záložky.
     *
     * @param array{next_date: ?string, is_active: int|bool}|array<string, mixed>|null $plan
     * @return array{label: string, tone: string, badge: string}
     */
    public static function cell(?array $plan, string $today): array
    {
        if ($plan === null || (int) $plan['is_active'] !== 1 || $plan['next_date'] === null) {
            return ['label' => $plan === null ? 'nenaplánováno' : 'vypnutý plán', 'tone' => 'faint', 'badge' => ''];
        }

        $days = self::daysUntil((string) $plan['next_date'], $today);
        $label = self::countdown((string) $plan['next_date'], $today);
        $tone = $days < 0 ? 'error' : ($days <= 3 ? 'warning' : '');

        return [
            'label' => $label,
            'tone' => $tone,
            'badge' => $days < 0 ? get_badge('po termínu', 'error') : ($days <= 7 ? get_badge($label, $days <= 3 ? 'warning' : '') : ''),
        ];
    }

    /** Přičtení měsíců s ořezem na konec kratšího měsíce (31. 1. + 1 = 28. 2.). */
    private static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $day = (int) $date->format('j');
        $firstOfMonth = $date->modify('first day of this month')->add(new DateInterval('P' . $months . 'M'));
        $lastDay = (int) $firstOfMonth->format('t');

        return $firstOfMonth->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), min($day, $lastDay));
    }

    /** Kolikátý termín v rytmu to je (0 = první datum). */
    private static function indexOf(string $firstDate, int $months, string $date): int
    {
        $first = new DateTimeImmutable($firstDate);
        $target = new DateTimeImmutable($date);
        $diff = ((int) $target->format('Y') - (int) $first->format('Y')) * 12 + ((int) $target->format('n') - (int) $first->format('n'));

        return intdiv(max(0, $diff), $months);
    }
}
