import { showToast } from './toast.js';

/**
 * Záložka Služby v detailu webu: klik na službu nebo úkol uloží hned,
 * bez načtení stránky (rozbalené skupiny tak zůstanou rozbalené).
 *
 * Řádek je tlačítko formuláře s požadovaným novým stavem
 * (`name="task[sitemap]" value="1"`). Skript odešle totéž na pozadí,
 * zaškrtnutí ukáže hned a z odpovědi přepíše podpis úkolu, počty
 * skupin a průběh. Když uložení selže, zaškrtnutí se vrátí.
 */
export function initSiteServices() {
    document.querySelectorAll('form[data-site-services]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const button = event.submitter;

            if (!button?.matches('[data-toggle]')) {
                return;
            }

            event.preventDefault();

            // Druhý klik během ukládání by poslal stejný stav znovu.
            if (button.dataset.busy) {
                return;
            }

            toggle(form, button);
        });
    });
}

async function toggle(form, button) {
    const on = button.value === '1';
    const body = new FormData();

    body.set('_token', form.querySelector('input[name="_token"]')?.value ?? '');
    body.set(button.name, button.value);
    button.dataset.busy = '1';
    button.setAttribute('aria-busy', 'true');
    setChecked(button, on);

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok !== true) {
            throw new Error(data.message || `Server odpověděl chybou ${response.status}.`);
        }

        setChecked(button, data.item.on);
        button.value = data.item.next;
        setMeta(button, data.item.meta);
        render(data);
    } catch (error) {
        setChecked(button, !on);
        showToast(`Neuloženo: ${error.message || 'spojení se správou se přerušilo.'}`);
    } finally {
        delete button.dataset.busy;
        button.removeAttribute('aria-busy');
    }
}

/** Stav řádku: `aria-checked` + modifikátory z návrhu (STAVY.md). */
function setChecked(button, on) {
    const modifier = button.dataset.toggle === 'task' ? 'launch-list__item--done' : 'service--on';

    button.setAttribute('aria-checked', on ? 'true' : 'false');
    button.classList.toggle(modifier, on);
    button.querySelector('.checkbox')?.classList.toggle('checkbox--checked', on);
}

/** Podpis úkolu „Jan N. · 14. 10. 2026" — jen u hotového. */
function setMeta(button, text) {
    if (button.dataset.toggle !== 'task') {
        return;
    }

    let meta = button.querySelector('.launch-list__meta');

    if (!text) {
        meta?.remove();
        return;
    }

    if (!meta) {
        meta = document.createElement('span');
        meta.className = 'launch-list__meta';
        button.append(meta);
    }

    meta.textContent = text;
}

/** Počty v hlavičkách karet a skupin. */
function render(data) {
    const servicesBadge = document.querySelector('[data-services-badge]');
    const note = document.querySelector('[data-launch-note]');
    const progress = document.querySelector('[data-launch-progress]');

    if (servicesBadge) {
        servicesBadge.textContent = data.servicesBadge;
    }

    if (note) {
        note.textContent = data.progress.note;
    }

    if (progress) {
        progress.style.setProperty('--progress', `${data.progress.percent}%`);
        progress.querySelector('.progress__count').textContent = data.progress.count;
    }

    Object.entries(data.groups ?? {}).forEach(([key, badge]) => {
        const element = document.querySelector(`[data-launch-group="${key}"] .launch-list__summary .badge`);

        if (element) {
            element.textContent = badge.text;
            element.classList.toggle('badge--ok', badge.tone === 'ok');
        }
    });
}
