<?php

declare(strict_types=1);

/**
 * Spouštěč testů: php tests/run.php
 *
 * Návratový kód 0 = vše prošlo (přeskočené testy nejsou chyba), 1 = selhání.
 */

require __DIR__ . '/bootstrap.php';

/**
 * Průběh se píše na chybový výstup, ne na standardní.
 *
 * Jakýkoli výstup do těla odpovědi znamená pro PHP „hlavičky odeslány" a
 * `session_regenerate_id()` v přihlašování pak skončí varováním — tedy
 * selháním testu, který s přihlašováním nemá co dělat. Na STDERR se tečky
 * ukazují dál živě a nic nerozbijí.
 */
function progress(string $mark): void
{
    fwrite(STDERR, $mark);
}

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
/** @var array<int, array{name: string, message: string}> $failed */
$failed = [];
/** @var array<int, string> $skipped */
$skipped = [];

foreach ($files as $file) {
    $suite = basename($file, 'Test.php');

    // Chyba při načítání souboru (syntax, deprecated hláška v deklaraci) musí
    // skončit jako selhaný test, ne jako fatal, který zabije celý běh.
    try {
        $tests = require $file;
    } catch (Throwable $e) {
        $failed[] = [
            'name' => $suite,
            'message' => 'Soubor testu se nepodařilo načíst: ' . $e->getMessage(),
        ];
        progress('F');
        continue;
    }

    if (!is_array($tests)) {
        $failed[] = ['name' => $suite, 'message' => 'Testovací soubor nevrací pole testů.'];
        continue;
    }

    foreach ($tests as $name => $test) {
        $label = $suite . ': ' . $name;

        try {
            $test();
            $passed++;
            progress('.');
        } catch (SkippedTest $e) {
            $skipped[] = $label . ' (' . $e->getMessage() . ')';
            progress('s');
        } catch (Throwable $e) {
            $failed[] = [
                'name' => $label,
                'message' => $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine(),
            ];
            progress('F');
        }
    }
}

echo "\n\n";

if ($skipped !== []) {
    echo "Přeskočeno:\n";
    foreach ($skipped as $item) {
        echo '  - ' . $item . "\n";
    }
    echo "\n";
}

if ($failed !== []) {
    echo "SELHALO:\n";
    foreach ($failed as $failure) {
        echo '  ✗ ' . $failure['name'] . "\n    " . $failure['message'] . "\n\n";
    }
}

printf(
    "%s  prošlo: %d, selhalo: %d, přeskočeno: %d\n",
    $failed === [] ? 'OK.' : 'CHYBA.',
    $passed,
    count($failed),
    count($skipped),
);

exit($failed === [] ? 0 : 1);
