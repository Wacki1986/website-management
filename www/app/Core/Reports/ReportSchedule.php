<?php

declare(strict_types=1);

namespace App\Core\Reports;

use DateTimeImmutable;

/**
 * Termíny a období klientských reportů — čistá logika bez databáze.
 *
 * Report za období odchází až po jeho konci: měsíční 1. dne (nebo jiného
 * zvoleného dne) dalšího měsíce za měsíc předchozí, čtvrtletní v prvním
 * měsíci čtvrtletí za čtvrtletí předchozí, týdenní ve zvolený den týdne
 * za předchozí celý týden (po–ne). Ruční report pokrývá posledních 30 dní.
 */
final class ReportSchedule
{
    /** @var array<string, array{label: string, note: string}> */
    public const FREQUENCIES = [
        'weekly' => ['label' => 'Týdně', 'note' => 'za předchozí týden (pondělí–neděle)'],
        'monthly' => ['label' => 'Měsíčně', 'note' => 'za předchozí kalendářní měsíc'],
        'quarterly' => ['label' => 'Čtvrtletně', 'note' => 'za předchozí čtvrtletí'],
        'manual' => ['label' => 'Jen ručně', 'note' => 'report vzniká jen tlačítkem „Odeslat report teď"'],
    ];

    public const WEEKDAYS = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];

    /** Dny v měsíci k výběru — max. 28, aby termín existoval i v únoru. */
    public const MAX_MONTH_DAY = 28;

    /**
     * Další odeslání po `$now` (nikdy rovno). Ruční režim termín nemá.
     *
     * @param int $sendDay den v měsíci (1–28), u týdenního dne v týdnu (1 = pondělí)
     */
    public static function nextSendAt(string $frequency, int $sendDay, int $sendHour, string $now): ?string
    {
        $from = new DateTimeImmutable($now);
        $hour = max(0, min(23, $sendHour));

        switch ($frequency) {
            case 'weekly':
                $day = max(1, min(7, $sendDay));
                $candidate = $from->setTime($hour, 0);
                $shift = ($day - (int) $from->format('N') + 7) % 7;
                $candidate = $candidate->modify('+' . $shift . ' days');

                if ($candidate <= $from) {
                    $candidate = $candidate->modify('+7 days');
                }

                return $candidate->format('Y-m-d H:i:s');

            case 'monthly':
            case 'quarterly':
                $day = max(1, min(self::MAX_MONTH_DAY, $sendDay));
                $year = (int) $from->format('Y');
                $month = (int) $from->format('n');

                // Procházet měsíce dopředu, dokud termín nepadne po `$now`
                // (u čtvrtletního jen leden, duben, červenec, říjen).
                for ($i = 0; $i < 15; $i++) {
                    $m = $month + $i;
                    $y = $year + intdiv($m - 1, 12);
                    $m = ($m - 1) % 12 + 1;

                    if ($frequency === 'quarterly' && !in_array($m, [1, 4, 7, 10], true)) {
                        continue;
                    }

                    $candidate = $from->setDate($y, $m, $day)->setTime($hour, 0);

                    if ($candidate > $from) {
                        return $candidate->format('Y-m-d H:i:s');
                    }
                }

                return null;

            default:
                return null;
        }
    }

    /**
     * Období, které report odesílaný v `$sendAt` pokrývá.
     *
     * @return array{from: string, to: string, label: string}
     */
    public static function period(string $frequency, string $sendAt): array
    {
        $at = new DateTimeImmutable($sendAt);

        switch ($frequency) {
            case 'weekly':
                // Předchozí celý týden: pondělí aktuálního týdne (ISO, pondělí
                // = 1) minus 7 dní; „monday this week" se v PHP u neděle chová
                // různě podle verze, proto ruční výpočet.
                $monday = $at->setTime(0, 0)->modify('-' . ((int) $at->format('N') - 1) . ' days');
                $from = $monday->modify('-7 days');
                $to = $from->modify('+6 days');

                return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => 'Týden ' . $from->format('j. n.') . ' – ' . $to->format('j. n. Y')];

            case 'quarterly':
                $quarter = intdiv((int) $at->format('n') - 1, 3); // 0–3 aktuální
                $year = (int) $at->format('Y');
                $prev = $quarter - 1;

                if ($prev < 0) {
                    $prev = 3;
                    $year--;
                }

                $from = $at->setDate($year, $prev * 3 + 1, 1);
                $to = $from->modify('+2 months')->modify('last day of this month');

                return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => ($prev + 1) . '. čtvrtletí ' . $year];

            case 'manual':
                $to = $at->modify('-1 day');
                $from = $to->modify('-29 days');

                return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $from->format('j. n.') . ' – ' . $to->format('j. n. Y')];

            default:
                $from = $at->modify('first day of last month');
                $to = $from->modify('last day of this month');

                return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => mb_convert_case(get_czech_month((int) $from->format('n')), MB_CASE_TITLE, 'UTF-8') . ' ' . $from->format('Y')];
        }
    }

    /** „Měsíčně, 1. den v měsíci" — popis nastavení pro poznámky karet. */
    public static function describe(string $frequency, int $sendDay, int $sendHour): string
    {
        $label = self::FREQUENCIES[$frequency]['label'] ?? 'Měsíčně';
        $time = sprintf('%02d:00', $sendHour);

        return match ($frequency) {
            'weekly' => $label . ', v ' . (self::WEEKDAYS[$sendDay] ?? 'pondělí') . ' v ' . $time,
            'monthly' => $label . ', ' . $sendDay . '. den v měsíci v ' . $time,
            'quarterly' => $label . ', ' . $sendDay . '. den prvního měsíce v ' . $time,
            default => $label,
        };
    }

    /** Krátký tvar pro seznamy: „měsíčně", „čtvrtletně", „ručně". */
    public static function shortLabel(string $frequency): string
    {
        return match ($frequency) {
            'weekly' => 'týdně',
            'quarterly' => 'čtvrtletně',
            'manual' => 'ručně',
            default => 'měsíčně',
        };
    }

    /**
     * Nabídka dne odeslání podle frekvence. @return array<int, string>
     */
    public static function sendDayOptions(string $frequency): array
    {
        if ($frequency === 'weekly') {
            return array_map(static fn (string $d): string => mb_convert_case($d, MB_CASE_TITLE, 'UTF-8'), self::WEEKDAYS);
        }

        $options = [];

        for ($d = 1; $d <= self::MAX_MONTH_DAY; $d++) {
            $options[$d] = $d . '. den v měsíci';
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function hourOptions(): array
    {
        $options = [];

        for ($h = 0; $h < 24; $h++) {
            $options[$h] = sprintf('%02d:00', $h);
        }

        return $options;
    }
}
