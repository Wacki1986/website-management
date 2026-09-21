<?php
/**
 * Obecná chybová stránka — 500 a stavy bez vlastní šablony (419, 429).
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 * @var int                 $status
 * @var string              $message
 * @var string|null         $marker
 */
$this->extend(($currentUser ?? null) === null ? 'layout/auth' : 'layout/shell', ['title' => $title]);

$isFault = $status >= 500;

$codeLabel = match ($status) {
    500 => '500 · Chyba aplikace',
    419 => '419 · Formulář vypršel',
    429 => '429 · Příliš mnoho pokusů',
    default => (string) $status,
};

$actions = $isFault
    ? '<a class="btn btn--primary" href="">Obnovit stránku</a>'
        . (($marker ?? null) !== null ? '<button type="button" class="btn btn--secondary" data-copy="' . $this->e($marker) . '">Zkopírovat značku chyby</button>' : '')
    : (back_url() !== null ? '<a class="btn btn--secondary" href="' . back_url() . '">Zpět</a>' : '')
        . '<a class="btn btn--primary" href="' . get_url('/') . '">Na dashboard</a>';

$text = $isFault
    ? 'Chyba je jen v tomhle nástroji — weby klientů běží dál a nic nepoznaly.'
        . (($marker ?? null) !== null ? ' Detail je v logu, značka ' . $marker . '.' : ' ' . $message)
    : $message;
?>
<div class="app__content">
    <section class="card">
        <div class="caps" style="text-align:center;padding-top:22px"><?= $this->e($codeLabel) ?></div>
        <?php render_empty($isFault ? 'Aplikace si dala pauzu' : $title, $text, $isFault ? 'alert' : 'clock', $actions) ?>
    </section>
</div>
