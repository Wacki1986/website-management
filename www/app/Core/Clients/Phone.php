<?php

declare(strict_types=1);

namespace App\Core\Clients;

/**
 * Telefon kontaktní osoby v jednom tvaru: „+420 603 213 408".
 *
 * Čísla se píšou všelijak (603213408, 00420 603-213-408, +420603213408)
 * a v seznamu pak nejdou porovnat ani přečíst. Upravuje se při uložení,
 * ne při výpisu, aby stejné číslo bylo v databázi stejně a hledání
 * „603 213" ho našlo.
 *
 * Pravidla:
 * - devět číslic bez předvolby = české číslo → „+420 …",
 * - 00 na začátku = mezinárodní předvolba (00420 → +420),
 * - české a slovenské číslo (+420, +421) se rozdělí po trojicích,
 * - cokoli jiného (cizí předvolba, poznámka u čísla, linka) zůstane, jak
 *   to správce napsal — špatně uhodnuté číslo je horší než neupravené.
 */
final class Phone
{
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        // Oddělovače, které lidé do čísel píšou; nic jiného se nezahazuje.
        $compact = (string) preg_replace('/[\s\-\.\/\(\)]+/u', '', $raw);

        if (preg_match('/^(\+|00)?(\d+)$/', $compact, $m) !== 1) {
            return $raw;
        }

        $international = $m[1] !== '';
        $digits = $m[2];

        if (!$international && strlen($digits) === 9) {
            $digits = '420' . $digits;
        } elseif (!$international && preg_match('/^42[01]\d{9}$/', $digits) !== 1) {
            return $raw;
        }

        if (preg_match('/^(42[01])(\d{3})(\d{3})(\d{3})$/', $digits, $parts) === 1) {
            return '+' . $parts[1] . ' ' . $parts[2] . ' ' . $parts[3] . ' ' . $parts[4];
        }

        return $raw;
    }
}
