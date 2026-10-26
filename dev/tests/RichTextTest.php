<?php

declare(strict_types=1);

/**
 * Poznámky s formátováním (`RichText`): text se značkami editoru → HTML
 * pro stránku, PDF a e-mail, nebo prostý text bez značek.
 */

use App\Core\Views\RichText;

return [
    'odstavce, zalomení, tučně, odkazy a seznamy' => function (): void {
        $html = RichText::html("Úvod **tučně** a [Wedos](https://wedos.cz)\ndruhý řádek\n\n- první\n- druhá\n1. jedna\n2. dvě\n\nkonec https://x.cz.", newWindow: false);

        assertSame(
            '<p>Úvod <b>tučně</b> a <a href="https://wedos.cz">Wedos</a><br>druhý řádek</p>'
            . '<ul><li>první</li><li>druhá</li></ul>'
            . '<ol><li>jedna</li><li>dvě</li></ol>'
            . '<p>konec <a href="https://x.cz">https://x.cz</a>.</p>',
            $html,
        );
    },

    'všechno ostatní se escapuje, nepovolený odkaz zůstane textem' => function (): void {
        $html = RichText::html('<script>alert(1)</script> [klik](javascript:alert(1)) **<b>x</b>** [mail](mailto:jan@firma.cz)', newWindow: false);

        assertFalse(str_contains($html, '<script'), 'Značka ze vstupu prošla');
        assertFalse(str_contains($html, 'href="javascript'), 'Nepovolený odkaz prošel');
        assertContainsString('&lt;script&gt;', $html);
        assertContainsString('<b>&lt;b&gt;x&lt;/b&gt;</b>', $html);
        assertContainsString('<a href="mailto:jan@firma.cz">mail</a>', $html);
    },

    'odkaz s uvozovkou v adrese nerozbije atribut; nové okno jen na přání' => function (): void {
        $html = RichText::html('[x](https://a.cz/"onmouseover=alert(1))');

        assertFalse(str_contains($html, '"onmouseover'), 'Uvozovka z adresy rozbila atribut');
        assertContainsString('target="_blank" rel="noopener noreferrer"', $html);
    },

    'starší poznámka bez značek se vypíše jako dřív' => function (): void {
        assertSame('<p>Kdy volat: ráno<br>Neměnit menu</p><p>Nový odstavec</p>', RichText::html("Kdy volat: ráno\nNeměnit menu\n\nNový odstavec"));
        assertSame('', RichText::html("  \n "));
    },

    'inline styly pro e-mail' => function (): void {
        $html = RichText::html("**a**\n- b", ['p' => 'margin:0;', 'b' => 'font-weight:700;', 'ul' => 'padding-left:20px;'], false);

        assertSame('<p style="margin:0;"><b style="font-weight:700;">a</b></p><ul style="padding-left:20px;"><li>b</li></ul>', $html);
    },

    'prostý text: bez značek, odkaz jako popis nebo s adresou' => function (): void {
        $text = "Hosting u [Wedosu](https://wedos.cz), **PIN 4455**\n- [jan@firma.cz](mailto:jan@firma.cz)\n- https://x.cz";

        assertSame("Hosting u Wedosu, PIN 4455\n- jan@firma.cz\n- https://x.cz", RichText::plain($text));
        assertSame("Hosting u Wedosu (https://wedos.cz), PIN 4455\n- jan@firma.cz\n- https://x.cz", RichText::plain($text, true));
    },

    'jeden řádek: řádky spojené tečkou, bez odrážek a čísel' => function (): void {
        assertSame('Ověřeno, vše v pořádku. · obnovit fotky · nový formulář', RichText::line("Ověřeno, **vše v pořádku**.\n\n- obnovit fotky\n1. [nový formulář](https://a.cz)"));
    },

    'helper get_rich_text: obal pro styly, prázdná poznámka nic' => function (): void {
        assertSame('<div class="rich-text-view"><p><b>a</b></p></div>', get_rich_text('**a**'));
        assertSame('', get_rich_text(''));
    },
];
