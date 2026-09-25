<?php
/**
 * Tabulka slabě hodnocených stránek (záložka SEO a „Zobrazit všechny").
 * Řádky připravuje `SeoController::pages()`.
 *
 * Skóre je číslo s pruhem; slovní hodnocení nese bublina a skrytý text —
 * stav se nesděluje jen barvou.
 *
 * @var \App\Core\View\View $this
 * @var array<int, array{title: string, type: string, keyword: string, keywordSet: bool, issues: array<int, array{label: string, title: string}>, score: int, tone: string, scoreTitle: string, url: string, editUrl: string}> $pages
 */
?>
<div class="table table--seo">
    <div class="table__head"><div>Stránka</div><div>Klíčové slovo</div><div>Co chybí</div><div>Skóre</div><div class="table__cell--right">Akce</div></div>
    <?php foreach ($pages as $page): ?>
        <div class="table__row">
            <div class="table__cell">
                <div class="table__primary u-truncate"><?= $this->e($page['title']) ?></div>
                <div class="table__secondary"><?= $this->e($page['type']) ?></div>
            </div>
            <div class="table__cell u-truncate <?= $page['keywordSet'] ? 'text-secondary' : 'text-faint' ?>"><?= $this->e($page['keyword']) ?></div>
            <div class="table__cell">
                <div class="seo-issues">
                    <?php foreach ($page['issues'] as $issue): ?>
                        <span class="pill pill--sm pill--muted" title="<?= $this->e($issue['title']) ?>"><?= $this->e($issue['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="table__cell">
                <div class="score-bar score-bar--<?= $page['tone'] ?>" title="<?= $this->e($page['scoreTitle']) ?>">
                    <span class="score-bar__track"><span class="score-bar__fill" style="width:<?= $page['score'] ?>%"></span></span>
                    <span class="score-bar__value"><?= $page['score'] ?></span>
                    <span class="visually-hidden"><?= $this->e($page['scoreTitle']) ?></span>
                </div>
            </div>
            <div class="table__cell table__cell--right">
                <div class="row-actions">
                    <?php if ($page['url'] !== ''): ?>
                        <a class="btn btn--ghost btn--icon" href="<?= $this->e($page['url']) ?>" target="_blank" rel="noopener" title="Otevřít stránku na webu" aria-label="Otevřít stránku <?= $this->e($page['title']) ?> na webu"><?= get_icon('external', 'icon--sm') ?></a>
                    <?php endif; ?>
                    <?php if ($page['editUrl'] !== ''): ?>
                        <a class="btn btn--ghost btn--icon" href="<?= $this->e($page['editUrl']) ?>" target="_blank" rel="noopener" title="Upravit ve wp-admin" aria-label="Upravit stránku <?= $this->e($page['title']) ?> ve wp-admin"><?= get_icon('edit', 'icon--sm') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
