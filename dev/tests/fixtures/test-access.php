<?php

declare(strict_types=1);

/**
 * `InstanceAccess` pro testy — pověření vůči falešné instanci.
 *
 * Falešné instance v `fixtures/fake-instance.php` běží na `127.0.0.1` a žádný
 * adresář na disku nemají, takže handshake u nich nikdy nevyjde a použije se
 * token. Přesně to je stav „instance na cizím serveru", a testy pozastavení,
 * migrací i odchodu klienta mají ověřovat právě tuhle záložní cestu — o tu
 * jinak není jak zakopnout.
 *
 * Handshake má vlastní test (`HandshakeTest`), kde se instance staví
 * i s adresářem.
 */

use App\Core\Instances\InstanceAccess;
use App\Core\Instances\InstanceRepository;
use App\Core\Provision\Handshake;

function testAccess(InstanceRepository $repo): InstanceAccess
{
    return new InstanceAccess($repo, new Handshake());
}
