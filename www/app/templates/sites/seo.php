<?php
/**
 * Detail webu — SEO (modul SEO). Návrh z Claude Design bez předávky,
 * prvky přiřazené k tokenům v `app/_seo.scss`; části se Search Console
 * (návštěvnost, pozice, graf) tu nejsou. Data připravuje `SeoController`.
 *
 * Vlevo tabulka nejslabších stránek, pod ní pokrytí metadat a nefunkční
 * odkazy; vpravo karta průměrného skóre a viditelnost.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array{title: string, text: string}|null $empty  proč zatím nejsou data (null = data jsou)
 * @var string|null         $checkedAt
 * @var array{tone: string, label: string, value: string, change: ?array{direction: string, text: string, title: string}, note: string, segments: array<int, array{tone: string, label: string, count: int}>}|null $score
 * @var array<int, array{label: string, tone: string, text: string, url: string}> $visibility
 * @var array<int, array{label: string, note: string, value: string, percent: int, tone: string}> $coverage
 * @var array{available: bool, title: string, badge: string, source: string, rows: array<int, array{url: string, hits: string}>, redirects: ?int}|null $notFound
 * @var array<int, array<string, mixed>> $pages       nejslabší stránky (řádky `partials/seo-pages-table`)
 * @var int                 $pagesTotal  kolik slabých stránek plugin poslal
 * @var string              $allPagesUrl
 * @var bool                $noPlugin    na webu není Rank Math ani Yoast
 * @var string              $upgradeNote plugin starší než 1.7.0 (bez pokrytí a 404)
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — SEO']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <?php if ($empty !== null): ?>
        <section class="card">
            <?php render_empty($empty['title'], $empty['text'], 'search') ?>
        </section>
    <?php else: ?>
        <?php if ($upgradeNote !== ''): ?>
            <?php render_notice($this, 'info', 'Víc údajů s novějším pluginem', $upgradeNote) ?>
        <?php endif; ?>

        <div class="split">
            <div class="stack">
                <?php if ($noPlugin): ?>
                    <?php render_notice($this, 'info', 'Na webu není SEO plugin', 'Skóre stránek počítá Rank Math nebo Yoast SEO. Bez nich se hlídá jen viditelnost pro vyhledávače, mapa webu, alt text obrázků a nefunkční odkazy.') ?>
                <?php else: ?>
                    <section class="card">
                        <?php render_card_head('Stránky se slabším hodnocením',
                            ($checkedAt !== null ? 'Načteno ' . get_when($checkedAt) . ' · ' : '') . 'nejslabší nahoře',
                            $pagesTotal > count($pages) ? '<a href="' . $allPagesUrl . '">Zobrazit všech ' . $pagesTotal . '</a>' : '') ?>

                        <?php if ($pages === []): ?>
                            <?php render_empty('Všechny hodnocené stránky jsou dobré', 'Žádná stránka nemá průměrné ani slabé skóre.', 'check') ?>
                        <?php else: ?>
                            <?= $this->partial('partials/seo-pages-table', ['pages' => $pages]) ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($coverage !== [] || $notFound !== null): ?>
                    <div class="seo-grid">
                        <?php if ($coverage !== []): ?>
                            <section class="card card--small-shadow">
                                <div class="card__header"><span class="caps">Pokrytí metadat</span></div>
                                <div class="coverage">
                                    <?php foreach ($coverage as $row): ?>
                                        <div class="coverage__row">
                                            <div class="coverage__head">
                                                <span class="coverage__label"<?= $row['note'] !== '' ? ' title="' . $this->e($row['note']) . '"' : '' ?>><?= $this->e($row['label']) ?></span>
                                                <span class="coverage__value coverage__value--<?= $row['tone'] ?>"><?= $this->e($row['value']) ?></span>
                                            </div>
                                            <span class="coverage__track"><span class="coverage__fill coverage__fill--<?= $row['tone'] ?>" style="width:<?= $row['percent'] ?>%"></span></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($notFound !== null): ?>
                            <section class="card card--small-shadow">
                                <div class="card__header">
                                    <span class="caps"><?= $this->e($notFound['title']) ?></span>
                                    <?php if ($notFound['badge'] !== ''): ?><?= get_badge($notFound['badge'], 'warning') ?><?php endif; ?>
                                </div>
                                <?php if (!$notFound['available']): ?>
                                    <?php render_empty('Web nefunkční odkazy nezaznamenává', 'Zapněte v Rank Math modul 404 Monitor, nebo nainstalujte plugin Redirection — pak tu budou adresy, na které návštěvníci narazili.', 'alert') ?>
                                <?php elseif ($notFound['rows'] === []): ?>
                                    <?php render_empty('Žádné nefunkční odkazy', 'Za posledních 7 dní návštěvníci na neexistující stránku nenarazili.', 'check') ?>
                                <?php else: ?>
                                    <div class="link-list">
                                        <?php foreach ($notFound['rows'] as $row): ?>
                                            <div class="link-list__item">
                                                <span class="link-list__url" title="<?= $this->e($row['url']) ?>"><?= $this->e($row['url']) ?></span>
                                                <span class="link-list__count"><?= $this->e($row['hits']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($notFound['redirects'] !== null): ?>
                                    <div class="summary-list">
                                        <div class="summary-list__row"><span class="summary-list__label">Aktivní přesměrování</span><span class="summary-list__value"><?= $notFound['redirects'] ?></span></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($notFound['source'] !== ''): ?>
                                    <div class="card__footer card__footer--muted"><span>Zdroj: <?= $this->e($notFound['source']) ?></span></div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="split__aside">
                <?php if ($score !== null): ?>
                    <div class="seo-score">
                        <div class="seo-score__label"><?= get_dot($score['tone']) ?><?= $this->e($score['label']) ?></div>
                        <div class="seo-score__headline">
                            <span class="seo-score__value"><?= $this->e($score['value']) ?></span>
                            <?php if ($score['change'] !== null): ?>
                                <span class="seo-score__change seo-score__change--<?= $score['change']['direction'] ?>" title="<?= $this->e($score['change']['title']) ?>"><?= $this->e($score['change']['text']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="seo-score__note"><?= $this->e($score['note']) ?></div>
                        <div class="seo-score__bar" aria-hidden="true">
                            <?php foreach ($score['segments'] as $segment): ?>
                                <?php if ($segment['count'] > 0): ?><span class="seo-score__segment seo-score__segment--<?= $segment['tone'] ?>" style="flex-grow:<?= $segment['count'] ?>"></span><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <ul class="seo-score__legend">
                            <?php foreach ($score['segments'] as $segment): ?>
                                <li class="seo-score__legend-item"><?= get_dot($segment['tone']) ?><span class="seo-score__legend-label"><?= $this->e($segment['label']) ?></span><span class="seo-score__legend-count"><?= $segment['count'] ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="card card--small-shadow">
                    <div class="summary-list">
                        <?php foreach ($visibility as $row): ?>
                            <div class="summary-list__row">
                                <span class="summary-list__label"><?= $this->e($row['label']) ?></span>
                                <span class="summary-list__value">
                                    <?php if ($row['url'] !== ''): ?>
                                        <a href="<?= $this->e($row['url']) ?>" target="_blank" rel="noopener"><?= get_status($row['tone'], $row['text']) ?></a>
                                    <?php else: ?>
                                        <?= get_status($row['tone'], $row['text']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card card--note">Skóre počítá SEO plugin při uložení stránky v editoru. Stránka, kterou od instalace pluginu nikdo neotevřel, je bez hodnocení. Stránky s noindex se nepočítají.</div>
            </aside>
        </div>
    <?php endif; ?>
</div>
