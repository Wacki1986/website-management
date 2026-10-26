<?php

declare(strict_types=1);

/**
 * Vykreslení šablon (`View`): chyba v šabloně nesmí nechat rozpracovaný
 * výstup v bufferu — chybová stránka by se jinak přilepila pod půlku
 * stránky i s menu.
 */

use App\Core\View\View;

return [
    'chyba v šabloně: rozpracovaný výstup se zahodí, výjimka projde dál' => function (): void {
        $dir = sys_get_temp_dir() . '/sprava-view-test-' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/boom.php', '<table><tr><td>půlka stránky</td></tr></table><?php throw new RuntimeException("bum");');
        file_put_contents($dir . '/outer.php', '<div>obal</div><?= $this->partial("boom") ?>');

        $level = ob_get_level();
        $caught = null;

        try {
            (new View($dir))->render('outer');
        } catch (RuntimeException $e) {
            $caught = $e->getMessage();
        }

        assertSame('bum', $caught);
        assertSame($level, ob_get_level(), 'V bufferu zůstal rozpracovaný výstup');

        @unlink($dir . '/boom.php');
        @unlink($dir . '/outer.php');
        @rmdir($dir);
    },
];
