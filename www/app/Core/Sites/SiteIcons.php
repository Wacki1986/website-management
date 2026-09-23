<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Db\Connection;
use App\Core\Http\HttpException;

/**
 * Ikona webu do seznamů — favicona stažená z webu, nebo ručně nahrané logo.
 *
 * Stahování nepotřebuje plugin: úvodní stránka webu nese v `<head>` odkazy
 * `<link rel="icon">` / `apple-touch-icon` (WordPress je tam dává sám, když
 * má web nastavenou „Ikonu webu"); když žádný není, zkusí se `/favicon.ico`.
 * Výchozí logo WordPressu, na které WordPress `/favicon.ico` přesměruje
 * u webu bez ikony, se nebere — u všech webů by bylo stejné „W".
 *
 * Soubor leží ve `storage/site-icons/` a ven jde routou jen přihlášeným
 * (jako profilové fotky). Typ se určuje z obsahu, SVG se nebere vůbec —
 * servírované z naší domény by mohlo spustit skript.
 */
final class SiteIcons
{
    /** MIME (z obsahu souboru) => přípona uloženého souboru. */
    public const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon' => 'ico',
    ];

    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    /** Jak často zkoušet stáhnout favicona znovu (web si ji mohl změnit). */
    public const REFRESH_DAYS = 7;

    /** Hrana čtverce po zmenšení — v seznamu je avatar 32 px, tohle stačí i na retinu. */
    private const SIZE = 128;

    private const MAX_HTML = 512 * 1024;
    private const MAX_REMOTE = 300 * 1024;
    private const MAX_UPLOAD = 2 * 1024 * 1024;
    private const DIRECTORY = 'site-icons';

    public function __construct(
        private readonly Connection $db,
        private readonly SiteRepository $sites,
        private readonly string $storagePath,
        private readonly int $timeout = 8,
        private readonly string $userAgent = 'MEDIAGRAFIK-Monitor/1.0 (+https://mediagrafik.cz)',
    ) {
    }

    // -----------------------------------------------------------------
    // Automatika
    // -----------------------------------------------------------------

    /**
     * Stáhne faviconu webu. Ručně nahrané logo nepřepisuje. Když se nic
     * nenajde, stará ikona zůstává — výpadek webu nesmí ikonu smazat.
     *
     * @param array<string, mixed> $site
     * @return bool true = web má teď ikonu
     */
    public function refresh(array $site): bool
    {
        if ((string) $site['icon_source'] === self::SOURCE_MANUAL) {
            return true;
        }

        $found = $this->download((string) $site['url']);
        $data = ['icon_checked_at' => date('Y-m-d H:i:s')];

        if ($found !== null) {
            $data += ['icon' => $this->save((int) $site['id'], $found['bytes'], $found['mime']), 'icon_source' => self::SOURCE_AUTO];
            $this->deleteFile((string) $site['icon']);
        }

        $this->sites->update((int) $site['id'], $data);

        return $found !== null || (string) $site['icon'] !== '';
    }

    /**
     * Krok monitoru: pár webů, které ikonu dlouho nekontrolovaly. Po
     * malých dávkách — stahování úvodní stránky není zadarmo a ikona
     * nikam nespěchá.
     *
     * @return int kolik webů se zkusilo
     */
    public function refreshStale(int $now, float $deadline, int $limit = 5): int
    {
        $sites = $this->db->select(
            "SELECT * FROM sites
             WHERE removed_at IS NULL AND icon_source <> :manual
               AND (icon_checked_at IS NULL OR icon_checked_at <= DATE_SUB(:now, INTERVAL " . self::REFRESH_DAYS . " DAY))
             ORDER BY icon_checked_at LIMIT " . max(1, $limit),
            ['manual' => self::SOURCE_MANUAL, 'now' => date('Y-m-d H:i:s', $now)],
        );
        $done = 0;

        foreach ($sites as $site) {
            if (microtime(true) > $deadline - 10) {
                break;
            }

            $this->refresh($site);
            $done++;
        }

        return $done;
    }

    // -----------------------------------------------------------------
    // Ručně nahrané logo
    // -----------------------------------------------------------------

    /**
     * Logo z formuláře. Má přednost před faviconou — automatika ho už
     * nepřepíše, dokud se neodebere.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    public function upload(int $siteId, ?array $file): void
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation('Vyberte nejdřív soubor s logem.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::validation('Nahrání souboru selhalo, zkuste to znovu.');
        }

        if ((int) $file['size'] > self::MAX_UPLOAD) {
            throw HttpException::validation('Logo je příliš velké (maximum jsou 2 MB).');
        }

        $bytes = (string) file_get_contents((string) $file['tmp_name']);
        $mime = self::mimeOfBytes($bytes);

        if ($mime === null) {
            throw HttpException::validation('Jako logo jde nahrát PNG, JPG, WebP, GIF nebo ICO (SVG ne — převeďte ho na PNG).');
        }

        $site = $this->sites->find($siteId);
        $this->sites->update($siteId, ['icon' => $this->save($siteId, $bytes, $mime), 'icon_source' => self::SOURCE_MANUAL]);
        $this->deleteFile((string) ($site['icon'] ?? ''));
    }

    /** Odebrání ikony — příští kontrola zkusí znovu stáhnout faviconu. */
    public function remove(int $siteId): void
    {
        $site = $this->sites->find($siteId);
        $this->deleteFile((string) ($site['icon'] ?? ''));
        $this->sites->update($siteId, ['icon' => '', 'icon_source' => '', 'icon_checked_at' => null]);
    }

    // -----------------------------------------------------------------
    // Soubory
    // -----------------------------------------------------------------

    public function absolutePath(string $fileName): string
    {
        return $this->storagePath . '/' . self::DIRECTORY . '/' . basename($fileName);
    }

    /** Content-Type pro výdej — z přípony uloženého jména. */
    public static function mimeOf(string $fileName): string
    {
        $mime = array_search(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::ALLOWED, true);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /** Typ obrázku podle obsahu, jen povolené (bez SVG). */
    public static function mimeOfBytes(string $bytes): ?string
    {
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        return isset(self::ALLOWED[$mime]) ? $mime : null;
    }

    /**
     * Odkazy na ikony z `<head>` stránky, nejvhodnější první: větší je
     * lepší (ostrá i na retině), `apple-touch-icon` bývá 180 px. SVG a
     * `data:` se přeskakují.
     *
     * @return array<int, string> absolutní adresy
     */
    public static function candidates(string $html, string $pageUrl): array
    {
        preg_match_all('/<link\b[^>]*>/i', $html, $tags);
        $scored = [];

        foreach ($tags[0] as $tag) {
            $rel = strtolower(self::attribute($tag, 'rel'));
            $href = html_entity_decode(self::attribute($tag, 'href'), ENT_QUOTES | ENT_HTML5);

            if (preg_match('/(^|\s)(icon|apple-touch-icon|apple-touch-icon-precomposed)(\s|$)/', $rel) !== 1 || $href === '' || str_starts_with($href, 'data:')) {
                continue;
            }

            if (str_contains(strtolower(self::attribute($tag, 'type')), 'svg') || preg_match('/\.svg($|\?)/i', $href) === 1) {
                continue;
            }

            $size = preg_match('/(\d+)x\d+/', self::attribute($tag, 'sizes'), $match) === 1 ? (int) $match[1] : (str_contains($rel, 'apple') ? 180 : 16);
            $url = self::resolve($href, $pageUrl);

            if ($url !== null) {
                $scored[$url] = max($scored[$url] ?? 0, min($size, 512));
            }
        }

        arsort($scored);

        return array_keys($scored);
    }

    /** Relativní odkaz ze stránky → absolutní adresa (jen http/https). */
    public static function resolve(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        $page = parse_url($pageUrl);

        if (!isset($page['scheme'], $page['host'])) {
            return null;
        }

        $origin = $page['scheme'] . '://' . $page['host'] . (isset($page['port']) ? ':' . $page['port'] : '');

        return match (true) {
            preg_match('#^https?://#i', $href) === 1 => $href,
            str_starts_with($href, '//') => $page['scheme'] . ':' . $href,
            str_starts_with($href, '/') => $origin . $href,
            preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1 => null,
            default => $origin . rtrim(dirname(($page['path'] ?? '/') . 'x'), '/') . '/' . $href,
        };
    }

    // -----------------------------------------------------------------

    /** @return array{bytes: string, mime: string}|null */
    private function download(string $siteUrl): ?array
    {
        $page = $this->get(rtrim($siteUrl, '/') . '/', self::MAX_HTML);
        $candidates = $page !== null ? self::candidates($page['body'], $page['url']) : [];
        $base = $page !== null ? $page['url'] : $siteUrl;
        $favicon = self::resolve('/favicon.ico', $base);

        if ($favicon !== null && !in_array($favicon, $candidates, true)) {
            $candidates[] = $favicon;
        }

        foreach (array_slice($candidates, 0, 4) as $url) {
            $image = $this->get($url, self::MAX_REMOTE);

            // Web bez ikony: WordPress přesměruje /favicon.ico na své logo.
            if ($image === null || str_contains($image['url'], '/wp-includes/images/w-logo')) {
                continue;
            }

            $mime = self::mimeOfBytes($image['body']);

            if ($mime !== null) {
                return ['bytes' => $image['body'], 'mime' => $mime];
            }
        }

        return null;
    }

    /**
     * GET s limitem velikosti — větší odpověď se utne a zahodí.
     *
     * @return array{body: string, url: string}|null jen odpověď 200
     */
    private function get(string $url, int $maxBytes): ?array
    {
        $body = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;

                // Vrácení jiné délky než přijaté curl přeruší.
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle) !== false;
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        return $ok && $status === 200 && $body !== '' ? ['body' => $body, 'url' => $finalUrl !== '' ? $finalUrl : $url] : null;
    }

    /**
     * Uloží obrázek pod novým náhodným jménem (změní se URL → prohlížeč
     * nevezme starou ikonu z cache). Větší obrázky se přes GD zmenší do
     * čtverce s průhledným okrajem — logo na šířku se neořízne.
     */
    private function save(int $siteId, string $bytes, string $mime): string
    {
        $directory = $this->storagePath . '/' . self::DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException(500, 'Nepodařilo se připravit úložiště pro ikony webů.');
        }

        $shrunk = $mime !== 'image/vnd.microsoft.icon' && $mime !== 'image/x-icon' ? self::shrink($bytes) : null;
        $extension = $shrunk !== null ? 'png' : self::ALLOWED[$mime];
        $fileName = $siteId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

        if (file_put_contents($directory . '/' . $fileName, $shrunk ?? $bytes) === false) {
            throw new HttpException(500, 'Ikonu webu se nepodařilo uložit.');
        }

        @chmod($directory . '/' . $fileName, 0644);

        return $fileName;
    }

    /** Zmenšení do čtverce `SIZE` × `SIZE` (PNG s průhledností); null = není potřeba nebo chybí GD. */
    private static function shrink(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::SIZE && $height <= self::SIZE && $width === $height) {
            return null;
        }

        $scale = min(self::SIZE / $width, self::SIZE / $height, 1);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagealphablending($target, true);
        imagecopyresampled($target, $source, (int) ((self::SIZE - $newWidth) / 2), (int) ((self::SIZE - $newHeight) / 2), 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($target);

        return (string) ob_get_clean();
    }

    private function deleteFile(string $fileName): void
    {
        if ($fileName !== '') {
            @unlink($this->absolutePath($fileName));
        }
    }

    private static function attribute(string $tag, string $name): string
    {
        return preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match) === 1
            ? ($match[2] !== '' ? $match[2] : ($match[3] ?? '') . ($match[4] ?? ''))
            : '';
    }
}
