<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Http\HttpException;

/**
 * Profilová fotka uživatele správy.
 *
 * Zjednodušená obdoba stejnojmenného servisu z klientské aplikace: správa
 * nemá evidenci souborů (`files`), takže fotka je jediný soubor ve
 * `storage/avatars/` pod náhodným jménem a `users.avatar` nese jen to jméno.
 * Storage není web root, ven jde soubor přes routu `/avatar/{id}` — tedy
 * jen přihlášeným.
 *
 * Nahraná fotka se zmenší a ořízne na čtverec přes GD; kde GD není, uloží
 * se originál a hlídá se jen typ a velikost. Typ se určuje z obsahu
 * (`getimagesize`), ne z přípony — stejná zásada jako u příloh podpory.
 */
final class Avatars
{
    /** MIME => přípona uloženého souboru; z přípony se pak servíruje Content-Type. */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** Hrana čtverce po zmenšení — stačí i na retina displeje. */
    private const SIZE = 256;

    /** Fotka je jen ozdoba — 4 MB bohatě stačí. */
    private const MAX_SIZE = 4 * 1024 * 1024;

    public function __construct(
        private readonly UserRepository $users,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Nahradí fotku uživatele souborem z $_FILES.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    public function replace(int $userId, ?array $file): void
    {
        $fileName = $this->store($file);

        // Stará fotka se maže až po úspěšném uložení nové — kdyby nahrání
        // spadlo, uživatel by zůstal bez obojího.
        $this->deleteCurrentFile($userId);
        $this->users->update($userId, ['avatar' => $fileName]);
    }

    /**
     * Zkontroluje, zmenší a uloží nahranou fotku; vrací jméno souboru ve
     * `storage/avatars/`. Bez vazby na účet — používá ho i profil podpory
     * v aplikacích (`Instances\SupportProfile`), jehož fotka k účtu nepatří.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    public function store(?array $file): string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation('Vyberte nejdřív soubor s fotkou.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::validation('Nahrání souboru selhalo, zkuste to znovu.');
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            throw HttpException::validation(sprintf(
                'Fotka je příliš velká (maximum je %d MB).',
                (int) (self::MAX_SIZE / 1024 / 1024),
            ));
        }

        $info = @getimagesize((string) $file['tmp_name']);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if (!isset(self::ALLOWED[$mime])) {
            throw HttpException::validation('Jako fotka jde nahrát jen obrázek (JPG, PNG, WebP nebo GIF).');
        }

        // Po zmenšení je soubor vždy JPEG; bez GD zůstane původní typ.
        $extension = $this->shrink($file, $mime) ? 'jpg' : self::ALLOWED[$mime];
        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = $this->storagePath . '/avatars';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException(500, 'Nepodařilo se připravit úložiště pro fotky.');
        }

        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $fileName)) {
            throw new HttpException(500, 'Fotku se nepodařilo uložit.');
        }

        @chmod($directory . '/' . $fileName, 0644);

        return $fileName;
    }

    /** Vrátí uživatele ke kolečku s iniciálami. */
    public function remove(int $userId): void
    {
        $this->deleteCurrentFile($userId);
        $this->users->update($userId, ['avatar' => null]);
    }

    /** Absolutní cesta k uloženému souboru — pro výdej routou. */
    public function absolutePath(string $fileName): string
    {
        return $this->storagePath . '/avatars/' . basename($fileName);
    }

    /** Content-Type pro výdej — z přípony uloženého jména. */
    public static function mimeOf(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mime = array_search($extension, self::ALLOWED, true);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /** Smaže uloženou fotku podle jména; prázdné jméno nic nedělá. */
    public function deleteFile(string $fileName): void
    {
        if ($fileName !== '') {
            @unlink($this->absolutePath($fileName));
        }
    }

    private function deleteCurrentFile(int $userId): void
    {
        $current = (string) ($this->users->find($userId)['avatar'] ?? '');

        if ($current !== '') {
            @unlink($this->absolutePath($current));
        }
    }

    /**
     * Zmenší a ořízne obrázek na čtverec. Vrací true, když se to povedlo —
     * výsledek je pak vždy JPEG (průhlednost v kolečku stejně není vidět
     * a soubor je řádově menší než PNG).
     *
     * @param array{tmp_name: string} $file
     */
    private function shrink(array $file, string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $path = (string) $file['tmp_name'];
        $info = @getimagesize($path);

        if ($info === false) {
            return false;
        }

        [$width, $height] = $info;

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if ($source === false) {
            return false;
        }

        // Ořez na střed: z fotky na výšku se vezme čtverec uprostřed, jinak
        // by se obličej po zploštění do kolečka roztáhl.
        $edge = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);

        imagecopyresampled(
            $target,
            $source,
            0, 0,
            (int) (($width - $edge) / 2),
            (int) (($height - $edge) / 2),
            self::SIZE, self::SIZE,
            $edge, $edge,
        );

        // Bez imagedestroy(): od PHP 8.5 je zastaralé a paměť uvolní GC sám.
        return imagejpeg($target, $path, 85);
    }
}
