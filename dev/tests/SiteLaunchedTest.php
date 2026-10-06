<?php

declare(strict_types=1);

/**
 * Datum spuštění webu — zadává se v Nastavení webu, ukazuje v přehledu.
 */

use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'datum spuštění: uloží se, ukáže v přehledu, prázdné pole ho smaže, nesmysl se odmítne' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $sites = $kernel->sites();
        $id = $sites->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $post = ['_token' => $token, 'url' => 'https://penam.cz', 'name' => 'Penam'];

        kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', $post + ['launched_on' => '2021-03-15']);
        assertSame('2021-03-15', $sites->find($id)['launched_on']);

        $overview = kernelRequest($kernel, 'GET', '/weby/' . $id)->body();
        assertContainsString('Spuštění webu', $overview);
        assertContainsString('15. 3. 2021', $overview);

        kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', $post + ['launched_on' => '2021-02-30']);
        assertSame('2021-03-15', $sites->find($id)['launched_on'], 'Neplatné datum se nesmělo uložit');

        kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', $post + ['launched_on' => '']);
        assertSame(null, $sites->find($id)['launched_on']);

        Urls::reset();
    },
];
