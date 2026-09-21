<?php

declare(strict_types=1);

/**
 * Pomocníci na časové údaje v šablonách správy.
 *
 * Polovina přehledu jsou relativní časy („před 2 min", „před 3 dny") —
 * píšou se na jednom místě, aby se česká skloňování nerozjela po šablonách.
 */

/**
 * „před 24 s" / „před 2 min" / „před 9 h" / „před 3 dny" — nebo pomlčka.
 */
function get_ago(?string $datetime, ?int $now = null): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return '—';
    }

    $seconds = max(0, ($now ?? time()) - $timestamp);

    // „před 0 s" vypadá jako chyba měření — do pěti vteřin je to teď.
    if ($seconds < 5) {
        return 'právě teď';
    }

    if ($seconds < 60) {
        return 'před ' . $seconds . ' s';
    }

    if ($seconds < 3600) {
        return 'před ' . intdiv($seconds, 60) . ' min';
    }

    if ($seconds < 86400) {
        return 'před ' . intdiv($seconds, 3600) . ' h';
    }

    $days = intdiv($seconds, 86400);

    return 'před ' . $days . ' ' . get_plural($days, 'dnem', 'dny', 'dny');
}

/** „1 min 12 s" — trvání mezi dvěma časy (fronta migrací, kroky založení). */
function get_duration(?string $from, ?string $to): string
{
    if ($from === null || $to === null) {
        return '';
    }

    $start = strtotime($from);
    $end = strtotime($to);

    if ($start === false || $end === false || $end < $start) {
        return '';
    }

    $seconds = $end - $start;

    if ($seconds < 60) {
        return $seconds . ' s';
    }

    return intdiv($seconds, 60) . ' min' . ($seconds % 60 !== 0 ? ' ' . ($seconds % 60) . ' s' : '');
}

/**
 * „za 47 min" / „za 6 h" / „za 2 d 6 h" — odpočet do budoucího času
 * (konec provozní zprávy). Prošlý či prázdný čas → pomlčka.
 */
function get_countdown(?string $datetime, ?int $now = null): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $timestamp = strtotime($datetime);
    $seconds = $timestamp === false ? -1 : $timestamp - ($now ?? time());

    if ($seconds < 0) {
        return '—';
    }

    if ($seconds < 3600) {
        return 'za ' . max(1, intdiv($seconds, 60)) . ' min';
    }

    if ($seconds < 86400) {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return 'za ' . $hours . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
    }

    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    return 'za ' . $days . ' d' . ($hours > 0 ? ' ' . $hours . ' h' : '');
}

/**
 * Název měsíce česky: `nominative` (srpen), `genitive` (srpna),
 * `locative` (v srpnu). Pro štítky období reportů a nadpisy e-mailu.
 */
function get_czech_month(int $month, string $case = 'nominative'): string
{
    $names = [
        'nominative' => ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'],
        'genitive' => ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'],
        'locative' => ['lednu', 'únoru', 'březnu', 'dubnu', 'květnu', 'červnu', 'červenci', 'srpnu', 'září', 'říjnu', 'listopadu', 'prosinci'],
    ];

    return $names[$case][$month - 1] ?? $names['nominative'][$month - 1] ?? '';
}

/** `2026-12-31` → `` (na výstupu vždy český tvar). */
function get_czech_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    $timestamp = strtotime($date);

    return $timestamp === false ? '—' : date('j. n. Y', $timestamp);
}

/**
 * „dnes 07:12" / „včera 06:00" / „zítra 06:00" / „19. 8. 06:00" — čas
 * s nejkratším srozumitelným dnem (stav cPanelu, běhy cronu). Rok se píše
 * jen u data z jiného roku. Prázdný vstup → pomlčka.
 */
function get_when(?string $datetime, ?int $now = null): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return '—';
    }

    $now ??= time();
    $day = date('Y-m-d', $timestamp);
    $time = date('H:i', $timestamp);

    if ($day === date('Y-m-d', $now)) {
        return 'dnes ' . $time;
    }

    if ($day === date('Y-m-d', $now - 86400)) {
        return 'včera ' . $time;
    }

    if ($day === date('Y-m-d', $now + 86400)) {
        return 'zítra ' . $time;
    }

    $sameYear = date('Y', $timestamp) === date('Y', $now);

    return date($sameYear ? 'j. n.' : 'j. n. Y', $timestamp) . ' ' . $time;
}
