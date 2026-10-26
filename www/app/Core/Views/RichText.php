<?php

declare(strict_types=1);

namespace App\Core\Views;

/**
 * Poznámky s formátováním — text se značkami z editoru `rich-text.js`:
 *
 *   **tučně**              [popis](https://adresa)   https://adresa
 *   - odrážka              1. číslovaný bod
 *
 * Ukládá se text, ne HTML. HTML skládá až tahle třída: všechno escapuje
 * a přidá jen svoje značky (p, br, ul, ol, li, b, a), odkaz jen na web
 * nebo e-mail. Pravidla jsou stejná jako v `rich-text.js` — při změně
 * upravit obě místa.
 *
 * Poznámky z doby před editorem jsou obyčejný text a vypíšou se stejně
 * jako dřív (odstavce a zalomení řádků).
 */
final class RichText
{
    private const INLINE = '~\[([^\]\n]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|(https?://[^\s<]*[^\s<.,;:!?)\]\'"])~u';

    private const SAFE_URL = '~^(https?://\S+|mailto:[^\s@]+@\S+)$~i';

    /**
     * HTML poznámky. `$styles` dává značkám inline styl (e-mail, PDF —
     * tam třídy z aplikace nejsou), klíče `p`, `ul`, `ol`, `li`, `a`, `b`.
     *
     * @param array<string, string> $styles
     */
    public static function html(string $text, array $styles = [], bool $newWindow = true): string
    {
        $open = static fn (string $tag, string $attributes = ''): string => '<' . $tag . $attributes
            . (isset($styles[$tag]) ? ' style="' . Format::plain($styles[$tag]) . '"' : '') . '>';
        $html = '';

        foreach (self::blocks($text) as $block) {
            if ($block['type'] === 'p') {
                $html .= $open('p') . implode('<br>', array_map(static fn (string $line): string => self::inline($line, $open, $newWindow), $block['lines'])) . '</p>';
                continue;
            }

            $html .= $open($block['type']);

            foreach ($block['lines'] as $line) {
                $html .= $open('li') . self::inline($line, $open, $newWindow) . '</li>';
            }

            $html .= '</' . $block['type'] . '>';
        }

        return $html;
    }

    /**
     * Prostý text bez značek — do tabulky, bubliny nebo textové verze
     * e-mailu. Seznamy si nechají „- " a čísla, odkaz je jen popis, nebo
     * s `$withUrls` „popis (adresa)".
     */
    public static function plain(string $text, bool $withUrls = false): string
    {
        $lines = [];

        foreach (explode("\n", self::normalize($text)) as $line) {
            $lines[] = preg_replace_callback(self::INLINE, static function (array $match) use ($withUrls): string {
                if (($match[1] ?? '') !== '') {
                    $url = preg_replace('~^mailto:~i', '', $match[2]);

                    return $withUrls && $url !== $match[1] ? $match[1] . ' (' . $url . ')' : $match[1];
                }

                return ($match[3] ?? '') !== '' ? $match[3] : $match[0];
            }, $line) ?? $line;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Jeden řádek pro tabulku nebo podpisek: řádky poznámky spojené „ · ",
     * bez odrážek a čísel seznamu.
     */
    public static function line(string $text): string
    {
        $parts = [];

        foreach (explode("\n", self::plain($text)) as $line) {
            $line = trim((string) preg_replace('~^\s*(?:[-•*]|\d+[.)])\s+~u', '', $line));

            if ($line !== '') {
                $parts[] = $line;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Řádky → odstavce a seznamy. Prázdný řádek odstavec ukončí, souvislé
     * řádky textu tvoří jeden odstavec se zalomením.
     *
     * @return array<int, array{type: string, lines: array<int, string>}>
     */
    private static function blocks(string $text): array
    {
        $blocks = [];
        $current = null;

        foreach (explode("\n", self::normalize($text)) as $line) {
            [$type, $content] = match (true) {
                preg_match('~^\s*(?:[-•]|\*)\s+(.*)$~u', $line, $m) === 1 => ['ul', $m[1]],
                preg_match('~^\s*\d+[.)]\s+(.*)$~u', $line, $m) === 1 => ['ol', $m[1]],
                trim($line) === '' => [null, ''],
                default => ['p', $line],
            };

            if ($type === null) {
                $current = null;
                continue;
            }

            if ($current === null || $blocks[$current]['type'] !== $type) {
                $blocks[] = ['type' => $type, 'lines' => []];
                $current = array_key_last($blocks);
            }

            $blocks[$current]['lines'][] = $content;
        }

        return $blocks;
    }

    /** @param callable(string, string=): string $open */
    private static function inline(string $text, callable $open, bool $newWindow): string
    {
        $html = '';
        $last = 0;
        $target = $newWindow ? ' target="_blank" rel="noopener noreferrer"' : '';

        preg_match_all(self::INLINE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $html .= Format::plain(substr($text, $last, $offset - $last));
            $last = $offset + strlen($whole);

            $html .= match (true) {
                $match[1][0] !== null && preg_match(self::SAFE_URL, $match[2][0]) === 1 => $open('a', ' href="' . Format::plain($match[2][0]) . '"' . $target) . Format::plain($match[1][0]) . '</a>',
                ($match[3][0] ?? null) !== null => $open('b') . self::inline($match[3][0], $open, $newWindow) . '</b>',
                ($match[4][0] ?? null) !== null => $open('a', ' href="' . Format::plain($match[4][0]) . '"' . $target) . Format::plain($match[4][0]) . '</a>',
                default => Format::plain($whole),
            };
        }

        return $html . Format::plain(substr($text, $last));
    }

    private static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($text, "\n\r"));
    }
}
