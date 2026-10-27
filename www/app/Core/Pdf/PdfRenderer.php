<?php

declare(strict_types=1);

namespace App\Core\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF (servisní protokol a další dokumenty pro klienty).
 *
 * Pod kapotou je Dompdf 3 (`app/vendor/dompdf`, LGPL) — přibalený celý
 * balíček z vydání na GitHubu i se závislostmi, bez Composeru. Aktualizace
 * = nahradit složku novým vydáním (postup v dev/docs/00-provoz.md).
 *
 * Dompdf umí jen jednodušší CSS: bloky, tabulky, okraje, barvy. Flexbox
 * ani grid ne — šablony PDF se proto skládají z tabulek.
 *
 * Písmo je Instrument Sans jako v aplikaci (`app/vendor/instrument-sans`,
 * licence OFL dovoluje vložení do PDF). Dompdf si z něj při prvním použití
 * spočítá metriky a uloží je do `storage/pdf` — proto musí být zapisovatelná.
 * Do PDF jde jen podmnožina použitých znaků, soubor zůstává malý.
 *
 * Z bezpečnosti Dompdf nesahá na síť ani mimo povolené složky: obrázky
 * a písma jen z `vendor/instrument-sans`, `assets/img` a `storage/brand`
 * (logo studia nahrané v Nastavení → Základní nastavení).
 */
final class PdfRenderer
{
    public const FONT_FAMILY = 'Instrument Sans';

    /** Řezy písma: váha v CSS => soubor. */
    private const FONT_FILES = [
        'normal' => 'InstrumentSans-Regular.ttf',
        '500' => 'InstrumentSans-Medium.ttf',
        '600' => 'InstrumentSans-SemiBold.ttf',
        'bold' => 'InstrumentSans-Bold.ttf',
    ];

    /**
     * @param string $rootPath  kořen aplikace (`www/`)
     * @param string $cacheDir  zapisovatelná složka pro metriky písem a dočasné soubory
     * @param string $brandDir  nahraná loga instance (`storage/brand`)
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly string $cacheDir,
        private readonly string $brandDir = '',
    ) {
    }

    /** Absolutní cesta k obrázku z `assets/img` — šablona PDF ho načte ze souboru, ne přes URL. */
    public function imagePath(string $file): string
    {
        return $this->rootPath . '/assets/img/' . $file;
    }

    /** Vykreslí celou HTML stránku do PDF (A4 na výšku) a vrátí obsah souboru. */
    public function render(string $html): string
    {
        // Knihovna se načítá až tady — ostatní stránky aplikace ji nepotřebují.
        require_once $this->rootPath . '/app/vendor/dompdf/autoload.inc.php';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }

        $options = new Options();
        $options->setFontDir($this->cacheDir);
        $options->setFontCache($this->cacheDir);
        $options->setTempDir($this->cacheDir);
        $options->setChroot(array_values(array_filter([$this->fontDir(), $this->rootPath . '/assets/img', $this->brandDir])));
        $options->setAllowedProtocols(['file://' => ['rules' => []], 'data://' => ['rules' => []]]);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(self::FONT_FAMILY);

        $dompdf = new Dompdf($options);
        $this->registerFonts($dompdf);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Instrument Sans se registruje jen poprvé — pak už ho Dompdf zná
     * ze svého seznamu v `storage/pdf` a metriky nepočítá znovu.
     */
    private function registerFonts(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();
        $known = $metrics->getFontFamilies()[mb_strtolower(self::FONT_FAMILY)] ?? [];

        foreach (self::FONT_FILES as $weight => $file) {
            if (!isset($known[$weight])) {
                $metrics->registerFont(['family' => self::FONT_FAMILY, 'weight' => (string) $weight, 'style' => 'normal'], $this->fontDir() . '/' . $file);
            }
        }
    }

    private function fontDir(): string
    {
        return $this->rootPath . '/app/vendor/instrument-sans';
    }
}
