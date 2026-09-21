<?php

declare(strict_types=1);

/**
 * Společné kusy pro testy samoobslužné registrace.
 *
 * Sdílí je `SignupTest` (logika) i `SignupApiTest` (HTTP). Ve fixture jsou
 * proto, že spouštěč načítá testové soubory podle abecedy — `SignupApiTest`
 * jde první a funkce ze `SignupTest` by v něm ještě neexistovaly.
 */

/**
 * Falešné jádro na disku.
 *
 * `Provisioner::moduleCatalog()` čte moduly ze složek `app/Modules/*`, takže
 * musí existovat aspoň dvě — jinak by instance vznikla bez modulů.
 */
function signupCoreDir(): string
{
    $dir = sys_get_temp_dir() . '/sprava-signup-core-' . getmypid();

    foreach (['Entries', 'Absences'] as $module) {
        @mkdir($dir . '/app/Modules/' . $module, 0775, true);
    }

    return $dir;
}

/** Prázdný adresář pro odchozí poštu (transport `log` do něj píše `.eml`). */
function signupMailDir(): string
{
    $dir = sys_get_temp_dir() . '/sprava-signup-mail-' . getmypid();
    @mkdir($dir, 0775, true);

    signupClearMail($dir);

    return $dir;
}

function signupClearMail(string $dir): void
{
    foreach (glob($dir . '/*.eml') ?: [] as $old) {
        @unlink($old);
    }
}

/**
 * Ověřovací token z posledního odeslaného e-mailu; prázdný = žádný nedorazil.
 *
 * Čte se z pošty schválně, ne z databáze: v `signups` je jen otisk a tímhle
 * se při každém testu ověří i to, že odkaz v e-mailu doopravdy funguje.
 */
function signupTokenFromMail(string $mailDir): string
{
    $files = glob($mailDir . '/*.eml') ?: [];

    if ($files === []) {
        return '';
    }

    // Seřadit podle času zápisu — v jednom testu může být e-mailů víc.
    usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

    // Tělo se posílá jako 8bit a dekódovat se nesmí: `quoted_printable_decode()`
    // by z `token=ee49…` udělal nesmysl, protože `=ee` vypadá jako escape.
    $body = (string) file_get_contents((string) end($files));

    return preg_match('/token=([0-9a-f]{64})/', $body, $match) === 1 ? $match[1] : '';
}
