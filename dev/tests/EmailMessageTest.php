<?php

declare(strict_types=1);

/**
 * Stavebnice e-mailů — obě podoby z jedné struktury.
 *
 * Hlídá hlavně to, kvůli čemu stavebnice vznikla: textová a HTML varianta
 * vznikají z týchž částí, takže nemůže nastat „v HTML je tlačítko, v textu
 * odkaz chybí". A že se všechno escapuje — texty zpráv nesou vstupy od lidí.
 */

use App\Core\Notifications\EmailMessage;

return [
    'obě varianty nesou tytéž části' => function (): void {
        $message = EmailMessage::make('Předplatné vypršelo')
            ->pill('předplatné vypršelo', 'danger')
            ->paragraph('Data zůstávají uložená.')
            ->infoBox(['Aplikace' => 'Autodoprava Křížek', 'Vypršelo' => '20. 8. 2026'])
            ->quote('Martina Kadlecová', 'DISPU podpora', '24. 8. · 09:12', 'Opravili jsme to.')
            ->button('Prodloužit předplatné', 'https://sprava.test/instance/5')
            ->smallprint('Po zaplacení se všechno obnoví.')
            ->footerReason('Posíláme správcům instance.');

        $text = $message->toText();
        assertContainsString('Předplatné vypršelo [předplatné vypršelo]', $text);
        assertContainsString('Aplikace: Autodoprava Křížek', $text);
        assertContainsString('Martina Kadlecová (DISPU podpora) · 24. 8. · 09:12:', $text);
        assertContainsString('Prodloužit předplatné: https://sprava.test/instance/5', $text);
        assertContainsString('Posíláme správcům instance.', $text);

        $html = $message->toHtml('DISPU', 'https://dispu.cz/logo.png');
        assertContainsString('logo.png', $html);
        assertContainsString('>Prodloužit předplatné</a>', $html);
        assertContainsString('https://sprava.test/instance/5', $html);
        assertContainsString('Autodoprava Křížek', $html);
        assertContainsString('>MK</span>', $html, 'Citace nese iniciály autora');
        assertContainsString('#fbe7e7', $html, 'Danger pilulka má barvu z design systému');
        assertContainsString('automatická zpráva', $html);

        // Bez loga nese hlavičku jméno odesílatele.
        assertContainsString('>DISPU</span>', $message->toHtml('DISPU'));
    },

    'obsah od lidí se escapuje' => function (): void {
        $html = EmailMessage::make('<script>x</script>')
            ->paragraph('Text s <b>HTML</b> a "uvozovkami".')
            ->button('Klik & hotovo', 'https://a.test/?x=1&y=2')
            ->toHtml('Firma & syn');

        assertFalse(str_contains($html, '<script>'), 'Nadpis se escapuje');
        assertContainsString('&lt;b&gt;HTML&lt;/b&gt;', $html);
        assertContainsString('Klik &amp; hotovo', $html);
        assertContainsString('Firma &amp; syn', $html);
    },
];
