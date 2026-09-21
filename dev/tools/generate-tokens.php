<?php

declare(strict_types=1);

/**
 * Vygeneruje `app_key` pro config/env.php správy.
 *
 * Spuštění: php dev/tools/generate-tokens.php
 *
 * Správa má oproti klientské aplikaci jediné tajemství v konfiguraci — klíč,
 * kterým šifruje tajemství v databázi. Tokeny jednotlivých instancí generují
 * instance samy (config/env.php u klienta) a podpisový klíč upozornění na
 * telefon si správa vyrobí sama v Nastavení → Oznámení.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skript se spouští z příkazové řádky.');
}

echo "Vygenerovaná hodnota — zkopírujte do config/env.php:\n\n";
printf("    'app_key' => '%s',\n\n", bin2hex(random_bytes(32)));

echo "'app_key' PO NASAZENÍ NEMĚŇTE — budou jím zašifrovaná tajemství v databázi\n"
    . "(tokeny instancí, API token hostingu, soukromý klíč pro upozornění na\n"
    . "telefon). Výměnou je znečitelníte — u upozornění to znamená, že si je\n"
    . "každý musí v telefonu zapnout znovu.\n"
    . "Zálohujte vyplněný config/env.php mimo server.\n";
