/**
 * Instalace pluginu z knihovny — okno „Přidat z knihovny" na záložce
 * Pluginy webu i „Nainstalovat na weby" v Knihovně pluginů (partial
 * `plugin-install-dialog`, otevírá ho `confirm-dialog.js`).
 *
 * Tlačítko Nainstalovat jde stisknout, až je něco zaškrtnuté. Pak se
 * zaškrtnuté položky posílají **po jednom** na akci instalace svého webu
 * (`data-action` = `weby/<id>/pluginy/instalovat`, `data-file` = plugin):
 * instalace stahuje a rozbaluje ZIP a všechny najednou by na hostingu
 * narazily na časový limit. Data z webu se načtou po posledním pluginu
 * daného webu (`refresh=1`) — v Knihovně tedy po každém webu, u webu jednou
 * na konci.
 *
 * Průběh je přímo v okně: nezaškrtnuté a nedostupné položky zmizí,
 * zaškrtávátko se vymění za točící se kolečko, pak fajfku, nebo křížek
 * s důvodem. Během běhu okno nejde zavřít (`aria-busy`); po konci se
 * zavřením stránka načte znovu, ať tabulka ukazuje nové pluginy.
 */
export function initPluginInstall() {
    const forms = document.querySelectorAll('form[data-plugin-install]');

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
        const slot = item.querySelector('[data-install-icon]');
        const note = item.querySelector('[data-install-note]');

        if (state === 'busy') {
            slot.replaceChildren(icon('refresh', 'icon--spin'));
            slot.title = 'Instaluje se';
            item.scrollIntoView({ block: 'nearest' });
            return;
        }

        if (detail !== '') {
            note.hidden = false;
            note.textContent = detail;
        }

        if (state === 'done' || state === 'note') {
            slot.replaceChildren(icon('check', 'icon--ok'));
            slot.title = 'Hotovo';
            item.classList.add('progress-list__item--done');
            note.classList.toggle('progress-list__note--muted', state === 'done');
            return;
        }

        slot.replaceChildren(icon('close', 'icon--warning'));
        slot.title = 'Nepodařilo se';
    };

    const send = async (form, target, activate, refresh) => {
        const body = new FormData();
        body.set('_token', form.querySelector('input[name="_token"]')?.value ?? '');
        body.set('plugin', target.dataset.file ?? '');
        body.set('activate', activate ? '1' : '0');
        body.set('refresh', refresh ? '1' : '0');

        const response = await fetch(target.dataset.action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        return response.json().catch(() => ({ ok: false, error: `Server odpověděl chybou ${response.status}.` }));
    };

    /** Výsledek jednoho pluginu → stav položky a věta pod ní. */
    const apply = (target, data, activate) => {
        const result = data.ok ? (data.items ?? []).find((entry) => entry.file === target.dataset.file) : null;

        if (result?.status === 'installed' && activate && !result.active) {
            // Nainstalovaný, ale aktivace selhala — hotovo, s varováním.
            setItem(target, 'note', result.message || 'Nainstalováno, ale plugin zůstal vypnutý.');
            return true;
        }

        if (result?.status === 'installed') {
            setItem(target, 'done', `Nainstalováno ${result.version ?? ''}${result.active ? ' a aktivní' : ' (vypnutý)'}.`);
            return true;
        }

        if (result?.status === 'skipped') {
            setItem(target, 'done', result.message || 'Plugin už na webu byl.');
            return true;
        }

        setItem(target, 'failed', result?.message || data.error || 'Web instalaci nevrátil.');
        return false;
    };

    const run = async (form) => {
        const dialog = form.closest('dialog');
        const progress = form.querySelector('[data-install-progress]');
        const text = form.querySelector('[data-install-progress-text]');
        const fill = form.querySelector('[data-install-progress-fill]');
        const start = form.querySelector('[data-install-start]');
        const close = form.querySelector('[data-install-close]');
        const activate = form.querySelector('[data-install-activate]')?.checked ?? false;
        const items = [...form.querySelectorAll('[data-install-item]')];
        const targets = items.filter((item) => item.querySelector('[data-install-check]')?.checked);

        if (targets.length === 0) {
            return;
        }

        running = true;
        dialog.setAttribute('aria-busy', 'true');
        start.disabled = true;
        close.disabled = true;
        progress.hidden = false;
        fill.style.width = '0';

        // V okně zůstane jen to, co se instaluje.
        items.filter((item) => !targets.includes(item)).forEach((item) => { item.hidden = true; });
        form.querySelectorAll('[data-install-skipped], [data-install-option]').forEach((element) => { element.hidden = true; });
        targets.forEach((item) => item.querySelector('[data-install-icon]').replaceChildren());

        let installed = 0;

        for (const [index, target] of targets.entries()) {
            text.textContent = `Instaluji ${index + 1} z ${targets.length}: ${target.dataset.name ?? ''}…`;
            setItem(target, 'busy');

            // Data z webu stačí načíst po jeho posledním pluginu.
            const refresh = !targets.slice(index + 1).some((next) => next.dataset.action === target.dataset.action);
            let data;

            try {
                data = await send(form, target, activate, refresh);
            } catch (error) {
                data = { ok: false, error: 'Spojení se správou se přerušilo.' };
            }

            installed += apply(target, data, activate) ? 1 : 0;
            fill.style.width = `${Math.round(((index + 1) / targets.length) * 100)}%`;
        }

        const failed = targets.length - installed;

        progress.classList.add('plugin-progress--done');
        text.textContent = `Hotovo: ${installed} z ${targets.length}.${failed > 0 ? ` Nepodařilo se u ${failed} — důvod je u položky.` : ''}`;

        running = false;
        dialog.removeAttribute('aria-busy');
        start.hidden = true;
        close.disabled = false;
        close.textContent = 'Zavřít';
        close.className = 'btn btn--primary';
        close.focus();

        dialog.addEventListener('close', () => window.location.reload(), { once: true });
    };

    // Zavření stránky uprostřed by zbylé položky tiše vynechalo — prohlížeč se zeptá.
    window.addEventListener('beforeunload', (event) => {
        if (running) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    forms.forEach((form) => {
        const dialog = form.closest('dialog');
        const start = form.querySelector('[data-install-start]');

        // Prohlížeč smí okno zavřít i přes zakázaný `cancel` (druhé Esc
        // v Chromu) — během běhu ho hned otevřít znovu, ať je průběh vidět.
        dialog?.addEventListener('close', () => {
            if (running && dialog.getAttribute('aria-busy') === 'true') {
                dialog.showModal();
            }
        });

        form.addEventListener('change', () => {
            if (start && !running) {
                start.disabled = form.querySelector('[data-install-check]:checked') === null;
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
