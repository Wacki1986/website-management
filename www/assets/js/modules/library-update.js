/**
 * „Aktualizovat všude" v Knihovně pluginů.
 *
 * Okno s výčtem webů otevírá `confirm-dialog.js` (ikona v Akcích řádku).
 * Po potvrzení se weby posílají **po jednom** na jejich akci aktualizace
 * (`weby/<id>/pluginy/aktualizovat`, stejná jako na záložce Pluginy webu):
 * jedna aktualizace trvá i desítky sekund a všechny najednou by na hostingu
 * narazily na časový limit. Každý požadavek si po sobě načte čerstvá data
 * z webu (`refresh=1`) a zapíše audit i historii webu.
 *
 * Průběh je přímo v okně: pruh „Aktualizuji 3 z 12: Penam CZ…" a vlevo
 * u každého webu prázdné kolečko → točící se → fajfka, nebo křížek
 * s důvodem pod názvem. Když selže jeden web, pokračuje se dalším. Během
 * běhu okno nejde zavřít (`aria-busy`); po konci se zavřením stránka načte
 * znovu, ať tabulka ukazuje nové verze.
 *
 * Patří sem i „+ 12 aktuálních" ve sloupci Na webech: rozbalí schované
 * pilulky na místě.
 */
export function initLibraryUpdate() {
    document.addEventListener('click', (event) => {
        const more = event.target.closest('[data-library-more]');

        if (more) {
            more.parentElement.querySelectorAll('[data-library-hidden]').forEach((pill) => { pill.hidden = false; });
            more.remove();
        }
    });

    const forms = document.querySelectorAll('form[data-library-update]');

    if (forms.length === 0 || !window.fetch) {
        return;
    }

    let running = false;

    const icon = (name, extraClass = '') => {
        const element = document.createElement('span');
        element.className = `icon icon--sm ${extraClass}`.trim();
        element.style.webkitMask = `var(--icon-${name}) center/contain no-repeat`;
        element.style.mask = `var(--icon-${name}) center/contain no-repeat`;
        element.setAttribute('aria-hidden', 'true');

        return element;
    };

    const setItem = (item, state, detail = '') => {
        const slot = item.querySelector('[data-library-icon]');
        const note = item.querySelector('[data-library-note]');

        if (state === 'busy') {
            slot.replaceChildren(icon('refresh', 'icon--spin'));
            slot.title = 'Aktualizuje se';
            item.scrollIntoView({ block: 'nearest' });
            return;
        }

        if (state === 'done') {
            slot.replaceChildren(icon('check', 'icon--ok'));
            slot.title = 'Hotovo';
            item.classList.add('progress-list__item--done');

            if (detail === '') {
                note.hidden = false;
                note.textContent = 'Web už měl nejnovější verzi.';
                note.classList.add('progress-list__note--muted');
            }

            return;
        }

        slot.replaceChildren(icon('close', 'icon--warning'));
        slot.title = 'Nepodařilo se';
        note.hidden = false;
        note.textContent = detail;
    };

    const send = async (form, action) => {
        const body = new FormData();
        body.set('_token', form.querySelector('input[name="_token"]')?.value ?? '');
        body.set('plugin', form.querySelector('input[name="plugin"]')?.value ?? '');
        body.set('refresh', '1');

        const response = await fetch(action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        return response.json().catch(() => ({ ok: false, error: `Server odpověděl chybou ${response.status}.` }));
    };

    const run = async (form) => {
        const dialog = form.closest('dialog');
        const progress = form.querySelector('[data-library-progress]');
        const text = form.querySelector('[data-library-progress-text]');
        const fill = form.querySelector('[data-library-progress-fill]');
        const start = form.querySelector('[data-library-start]');
        const close = form.querySelector('[data-library-close]');
        const file = form.querySelector('input[name="plugin"]')?.value ?? '';
        const targets = [...form.querySelectorAll('[data-library-target]')];

        running = true;
        dialog.setAttribute('aria-busy', 'true');
        start.disabled = true;
        close.disabled = true;
        progress.hidden = false;
        fill.style.width = '0';

        let updated = 0;
        let failed = 0;

        for (const [index, target] of targets.entries()) {
            text.textContent = `Aktualizuji ${index + 1} z ${targets.length}: ${target.dataset.name ?? ''}…`;
            setItem(target, 'busy');

            let data;

            try {
                data = await send(form, target.dataset.action);
            } catch (error) {
                data = { ok: false, error: 'Spojení se správou se přerušilo.' };
            }

            const result = data.ok ? (data.items ?? []).find((entry) => entry.file === file) : null;

            if (result?.status === 'updated') {
                updated++;
                setItem(target, 'done', result.to ?? '');
            } else if (result?.status === 'up_to_date') {
                setItem(target, 'done');
            } else {
                // Bez výsledku pro plugin: web aktualizaci mezitím přestal
                // nabízet (někdo ho aktualizoval ručně, nová kontrola…).
                failed++;
                setItem(target, 'failed', result?.message || data.error || 'Web aktualizaci nevrátil.');
            }

            fill.style.width = `${Math.round(((index + 1) / targets.length) * 100)}%`;
        }

        progress.classList.add('plugin-progress--done');
        text.textContent = `Hotovo: aktualizováno ${updated} z ${targets.length}.${failed > 0 ? ` Nepodařilo se u ${failed} — důvod je u webu.` : ''}`;

        running = false;
        dialog.removeAttribute('aria-busy');
        start.hidden = true;
        close.disabled = false;
        close.textContent = 'Zavřít';
        close.className = 'btn btn--primary';
        close.focus();

        // Zavřením (tlačítko, Esc, klik vedle) se stránka načte znovu —
        // tabulka pak ukáže nové verze a počty.
        dialog.addEventListener('close', () => window.location.reload(), { once: true });
    };

    // Zavření stránky uprostřed by zbylé weby tiše vynechalo — prohlížeč se zeptá.
    window.addEventListener('beforeunload', (event) => {
        if (running) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    forms.forEach((form) => {
        const dialog = form.closest('dialog');

        // Prohlížeč smí okno zavřít i přes zakázaný `cancel` (druhé Esc
        // v Chromu) — během běhu ho hned otevřít znovu, ať je průběh vidět.
        dialog?.addEventListener('close', () => {
            if (running && dialog.getAttribute('aria-busy') === 'true') {
                dialog.showModal();
            }
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (!running) {
                run(form);
            }
        });
    });
}
