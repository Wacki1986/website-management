<?php

declare(strict_types=1);

/**
 * Bezpečnostní kontrola: sloučení vnitřních a vnějších zjištění, skóre,
 * vnější sondy proti falešnému webu a konec podpory PHP.
 */

use App\Core\Monitor\OutsideProbe;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\SecurityAudit;

require_once __DIR__ . '/fixtures/FakeInstance.php';

return [
    'sloučení: pět opatření v pevném pořadí, chybějící zdroj = nezjištěno' => function (): void {
        $inside = [
            'security_plugin' => ['status' => 'ok', 'value' => 'Wordfence 8.0.1', 'note' => 'Firewall běží'],
            'two_factor' => ['status' => 'warning', 'value' => '1 z 3', 'note' => 'Zapnuto u 1 ze 3'],
            // content_dir chybí — plugin ho neposlal
        ];
        $outside = [
            'login_url' => ['status' => 'error', 'value' => '/wp-login.php', 'note' => 'veřejný'],
            'basic_auth' => ['status' => 'ok', 'value' => 'Zapnuto', 'note' => 'za heslem'],
        ];

        $result = SecurityAudit::merge($inside, $outside, '2026-09-18 08:35:00');

        assertSame(['security_plugin', 'login_url', 'two_factor', 'content_dir', 'basic_auth'], array_column($result['checks'], 'id'));
        assertSame(2, $result['deployed']);
        assertSame(1, $result['partial']);
        assertSame(1, $result['missing']);
        assertSame(1, $result['unknown']);
        assertSame('error', $result['tone']);
        assertSame('Nezjištěno', $result['checks'][3]['statusLabel']);
    },

    'skóre: bez chybějících je tón warning, bez výhrad ok' => function (): void {
        $ok = static fn (): array => ['status' => 'ok', 'value' => 'x', 'note' => ''];
        $all = ['security_plugin' => $ok(), 'two_factor' => $ok(), 'content_dir' => $ok()];
        $out = ['login_url' => $ok(), 'basic_auth' => $ok()];

        assertSame('ok', SecurityAudit::merge($all, $out, 'x')['tone']);

        $out['basic_auth'] = ['status' => 'warning', 'value' => 'x', 'note' => ''];
        assertSame('warning', SecurityAudit::merge($all, $out, 'x')['tone']);
    },

    'vnější sondy: veřejný login a chybějící Basic auth' => function (): void {
        if (!FakeInstance::start(8211, ['FAKE_WP_LOGIN' => 'public'], 'fake-wp-site.php')) {
            skip('falešný web nenastartoval: ' . FakeInstance::lastError());
        }

        try {
            $probe = new OutsideProbe(timeout: 5);
            $login = $probe->loginPage('http://127.0.0.1:8211');
            $admin = $probe->adminBasicAuth('http://127.0.0.1:8211');

            assertSame('error', $login['status']);
            assertSame('/wp-login.php', $login['value']);
            assertSame('error', $admin['status']);
            assertSame('Vypnuto', $admin['value']);
        } finally {
            FakeInstance::stop();
        }
    },

    'vnější sondy: schovaný login a Basic auth na administraci' => function (): void {
        if (!FakeInstance::start(8212, ['FAKE_WP_LOGIN' => 'hidden', 'FAKE_WP_BASIC' => '1'], 'fake-wp-site.php')) {
            skip('falešný web nenastartoval: ' . FakeInstance::lastError());
        }

        try {
            $probe = new OutsideProbe(timeout: 5);
            assertSame('ok', $probe->loginPage('http://127.0.0.1:8212')['status']);
            assertSame('ok', $probe->adminBasicAuth('http://127.0.0.1:8212')['status']);
            assertSame('Zapnuto', $probe->adminBasicAuth('http://127.0.0.1:8212')['value']);
        } finally {
            FakeInstance::stop();
        }
    },

    'vnější sondy: web neodpovídá = nezjištěno, ne chybí' => function (): void {
        $probe = new OutsideProbe(timeout: 2);
        assertSame('unknown', $probe->loginPage('http://127.0.0.1:8299')['status']);
        assertSame('unknown', $probe->adminBasicAuth('http://127.0.0.1:8299')['status']);
    },

    'PHP: konec podpory podle tabulky' => function (): void {
        $now = strtotime('2026-09-18');

        assertTrue(PhpSupport::isEol('7.4.33', $now));
        assertTrue(PhpSupport::isEol('8.0.30', $now));
        assertFalse(PhpSupport::isEol('8.2.20', $now));
        assertFalse(PhpSupport::isEol('8.3.1', $now));
        assertFalse(PhpSupport::isEol('9.1.0', $now), 'Verze novější než tabulka je podporovaná');
        assertTrue(PhpSupport::isEol('5.6.40', $now), 'Verze starší než tabulka je dávno bez podpory');
        assertSame('8.1', PhpSupport::minor('8.1.29'));
        assertTrue(PhpSupport::daysLeft('8.2.0', $now) > 0);
        assertTrue(PhpSupport::daysLeft('7.4.0', $now) < 0);
        assertSame(null, PhpSupport::daysLeft('9.9.9', $now));
    },
];
