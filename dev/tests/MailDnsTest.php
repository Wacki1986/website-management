<?php

declare(strict_types=1);

/**
 * DNS pošty u domén: vyhodnocení MX/SPF/DMARC/DKIM nad podvrženými
 * odpověďmi DNS (bez sítě), stav do řádku domény, upozornění jen na změnu
 * a zobrazení v úpravě domény.
 */

use App\Core\Monitor\MailDnsCheck;
use App\Core\Projects\MailDns;
use App\Core\Projects\ProjectRepository;
use App\Core\Projects\ProjectServices;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/monitor-fixture.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/**
 * Podvržené DNS: `host|TYP` => záznamy; chybějící klíč = žádné záznamy.
 *
 * @param array<string, array<int, string>|null> $zone
 */
function fakeDns(array $zone): MailDnsCheck
{
    return new MailDnsCheck(static fn (string $host, string $type): ?array => array_key_exists($host . '|' . $type, $zone) ? $zone[$host . '|' . $type] : []);
}

/** Zdravá doména na Google Workspace. @return array<string, array<int, string>> */
function healthyZone(): array
{
    return [
        'pekarnanovak.cz|MX' => ['aspmx.l.google.com', 'alt1.aspmx.l.google.com'],
        'pekarnanovak.cz|TXT' => ['google-site-verification=abc', 'v=spf1 include:_spf.google.com ~all'],
        '_dmarc.pekarnanovak.cz|TXT' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@pekarnanovak.cz'],
        'google._domainkey.pekarnanovak.cz|TXT' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkq'],
    ];
}

return [
    'zdravá doména: MX, SPF, DMARC s politikou a DKIM z obvyklého selektoru' => function (): void {
        $result = fakeDns(healthyZone())->check('pekarnanovak.cz');

        assertTrue($result['ok']);
        assertSame(['aspmx.l.google.com', 'alt1.aspmx.l.google.com'], $result['mx']);
        assertSame('v=spf1 include:_spf.google.com ~all', $result['spf']);
        assertSame('quarantine', $result['dmarc_policy']);
        assertSame('google', $result['dkim']);
        assertSame([], $result['issues']);
        assertSame(['tone' => 'ok', 'label' => 'pošta v pořádku'], MailDnsCheck::summary($result));
    },

    'problémy: chybí SPF a DMARC, dva SPF, +all, chybí MX, DNS neodpověděl; DKIM ze Seznamu' => function (): void {
        $zone = healthyZone();

        $bare = fakeDns(['pekarnanovak.cz|MX' => ['mx.wedos.net']])->check('pekarnanovak.cz');
        assertSame(['no_spf', 'no_dmarc'], $bare['issues']);
        assertSame('pošta: chybí SPF +1', MailDnsCheck::summary($bare)['label']);

        $double = fakeDns(['pekarnanovak.cz|TXT' => ['v=spf1 a ~all', 'v=spf1 mx ~all']] + $zone)->check('pekarnanovak.cz');
        assertSame(['multi_spf'], $double['issues']);
        assertSame('error', MailDnsCheck::summary($double)['tone']);

        $open = fakeDns(['pekarnanovak.cz|TXT' => ['v=spf1 +all']] + $zone)->check('pekarnanovak.cz');
        assertSame(['spf_all'], $open['issues']);

        $noMail = fakeDns(['pekarnanovak.cz|MX' => []])->check('pekarnanovak.cz');
        assertSame(['no_mx'], $noMail['issues'], 'Doména bez pošty: jen MX, ne SPF/DMARC šum');

        // Nenalezený DKIM není problém — selektor zvenku nejde zjistit vždy.
        unset($zone['google._domainkey.pekarnanovak.cz|TXT']);
        $noDkim = fakeDns($zone)->check('pekarnanovak.cz');
        assertSame([], $noDkim['issues']);
        assertSame(null, $noDkim['dkim']);

        $seznam = fakeDns(['szn2._domainkey.pekarnanovak.cz|TXT' => ['v=DKIM1; k=rsa; p=MIGf']] + $zone)->check('pekarnanovak.cz');
        assertSame('szn2', $seznam['dkim']);

        $down = fakeDns(['pekarnanovak.cz|MX' => null])->check('pekarnanovak.cz');
        assertFalse($down['ok']);
        assertSame('pošta: DNS neodpověděl', MailDnsCheck::summary($down)['label']);
        assertSame('pošta: zatím nekontrolováno', MailDnsCheck::summary(null)['label']);
    },

    'cron: první kontrola tichá, změna MX jednou e-mailem, beze změny nic' => function (): void {
        $f = monitorFixture();
        $services = new ProjectServices($f['db']);
        $projectId = (new ProjectRepository($f['db']))->create(['name' => 'pekarnanovak.cz']);
        $domain = $services->create($projectId, 'domain', ['name' => 'pekarnanovak.cz']);
        $mails = static fn (): int => count(glob($f['logDir'] . '/*.eml') ?: []);
        $now = (int) strtotime('2026-10-05 09:00:00');

        (new MailDns($services, fakeDns(healthyZone()), $f['notifier'], $f['monitorSettings']))->step($now, microtime(true) + 30);
        assertSame([], ProjectServices::mailDns($services->find($domain))['issues']);
        assertSame(0, $mails(), 'První kontrola nehlásí výchozí stav');

        // Další den beze změny: nic.
        (new MailDns($services, fakeDns(healthyZone()), $f['notifier'], $f['monitorSettings']))->step($now + 86400, microtime(true) + 30);
        assertSame(0, $mails());

        // Pošta se přestěhovala a DMARC zmizel.
        $moved = ['pekarnanovak.cz|MX' => ['mx1.wedos.net'], '_dmarc.pekarnanovak.cz|TXT' => []] + healthyZone();
        (new MailDns($services, fakeDns($moved), $f['notifier'], $f['monitorSettings']))->step($now + 2 * 86400, microtime(true) + 30);
        assertSame(1, $mails());
        $mail = (string) file_get_contents((glob($f['logDir'] . '/*.eml') ?: [''])[0]);
        assertContainsString('mx1.wedos.net', $mail);
        assertContainsString('DMARC', $mail);

        // Kontrola do 20 hodin se neopakuje.
        assertSame(0, (new MailDns($services, fakeDns($moved), $f['notifier'], $f['monitorSettings']))->step($now + 2 * 86400 + 3600, microtime(true) + 30));
    },

    'úprava domény ukazuje záznamy a vysvětlení, řádek domény stav pošty' => function (): void {
        [$kernel] = loggedInKernel();
        $project = $kernel->projects()->create(['name' => 'pekarnanovak.cz']);
        $domain = $kernel->projectServices()->create($project, 'domain', ['name' => 'pekarnanovak.cz']);
        $kernel->projectServices()->saveMailDns($domain, fakeDns(['pekarnanovak.cz|MX' => ['mx.wedos.net']])->check('pekarnanovak.cz'), date('Y-m-d H:i:s'));

        assertContainsString('pošta: chybí SPF +1', kernelRequest($kernel, 'GET', '/projekty/' . $project)->body());

        $edit = kernelRequest($kernel, 'GET', '/projekty/' . $project . '/sluzby/' . $domain . '/upravit')->body();
        assertContainsString('mx.wedos.net', $edit);
        assertContainsString('Bez DMARC záznamu', $edit);
        assertContainsString('formaction="/projekty/' . $project . '/sluzby/' . $domain . '/dns"', $edit);

        assertFalse(str_contains($edit, 'name="dkim_selector"'), 'Selektor se nezadává');

        Urls::reset();
    },
];
