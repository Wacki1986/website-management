/**
 * Aktualizace pluginů s průběhem (záložka Pluginy u webu).
 *
 * Bez skriptu odejde formulář najednou a stránka se po chvíli překreslí.
 * Skript místo toho posílá pluginy po jednom (JSON) a ukazuje, co se děje:
 * pruh „Aktualizuji 2 z 5…" nad tabulkou, točící se ikona u právě
 * aktualizovaného pluginu, ✓ a nová verze u hotového, × s důvodem u
 * neúspěšného. Data z webu se na serveru načtou až po posledním pluginu.
 *
 * Vypínač (aktivovat/deaktivovat) a oko (sledovat/nesledovat) mají
 * `formaction` jinam a odcházejí normálně — jen se hned ukáže, že se něco
 * děje: ikona se změní na točící se kolečko, řádek se ztlumí, pruh nad
 * tabulkou řekne co (`data-busy-text`) a druhé kliknutí se zahodí.
 */
export function initPluginUpdate() {
    const form = document.querySelector('form[data-plugin-update]');

    if (!form || !window.fetch) {
        return;
    }

    const progress = form.querySelector('[data-plugin-progress]');
    const text = form.querySelector('[data-plugin-progress-text]');
    const fill = form.querySelector('[data-plugin-progress-fill]');
    const bulk = document.querySelector('[data-plugin-bulk]');
    let running = false;

    const rowOf = (file) => form.querySelector(`[data-plugin-row="${CSS.escape(file)}"]`);

    const setIcon = (button, name, extraClass = '') => {
        const icon = button?.querySelector('.icon');

        if (!icon) {
            return;
        }

        icon.style.webkitMask = `var(--icon-${name}) center/contain no-repeat`;
        icon.style.mask = `var(--icon-${name}) center/contain no-repeat`;
        icon.className = `icon icon--sm ${extraClass}`.trim();
    };

    const setRow = (row, state, detail = '') => {
        if (!row) {
            return;
        }

        const button = row.querySelector('[data-plugin-update-one]');

        if (state === 'busy') {
            row.setAttribute('aria-busy', 'true');
            setIcon(button, 'refresh', 'icon--spin');
            return;
        }

        row.removeAttribute('aria-busy');

        if (state === 'done') {
            setIcon(button, 'check', 'icon--ok');

            if (button) {
                button.dataset.finished = '1';
            }

            button?.setAttribute('title', detail !== '' ? `Aktualizováno na ${detail}` : 'Už v nejnovější verzi');

            if (detail !== '') {
                const version = row.querySelector('[data-plugin-version]');
                const newer = row.querySelector('[data-plugin-new]');

                if (version) {
                    version.textContent = detail;
                }

                if (newer) {
                    newer.textContent = '—';
                    newer.classList.remove('text-warning');
                }
            }

            const checkbox = row.querySelector('input[name="plugins[]"]');

            if (checkbox) {
                checkbox.checked = false;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                checkbox.disabled = true;
                checkbox.dataset.finished = '1';
            }

            return;
        }

        setIcon(button, 'close', 'icon--warning');
        button?.setAttribute('title', `Nepodařilo se: ${detail}`);
    };

    const lock = (locked) => {
        form.querySelectorAll('button, input[type="checkbox"]').forEach((control) => {
            if (locked) {
                control.dataset.wasDisabled = control.disabled ? '1' : '';
                control.disabled = true;
            } else if (control.dataset.wasDisabled !== undefined) {
                // Hotové řádky zůstávají zamčené — druhá aktualizace by nic neudělala.
                control.disabled = control.dataset.wasDisabled === '1' || control.dataset.finished === '1';
                delete control.dataset.wasDisabled;
            }
        });

        if (bulk) {
            bulk.disabled = locked;
        }
    };

    const send = async (file, last) => {
        const body = new FormData();
        body.set('_token', form.querySelector('input[name="_token"]')?.value ?? '');
        body.set('plugin', file);
        body.set('refresh', last ? '1' : '0');

        const response = await fetch(form.action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        return response.json().catch(() => ({ ok: false, error: `Server odpověděl chybou ${response.status}.` }));
    };

    const run = async (files) => {
        running = true;
        lock(true);
        progress.hidden = false;
        progress.classList.remove('plugin-progress--done', 'plugin-progress--indeterminate');
        fill.style.width = '0';

        let updated = 0;
        const failed = [];

        for (const [index, file] of files.entries()) {
            const row = rowOf(file);
            const name = row?.dataset.pluginName ?? file;

            text.textContent = `Aktualizuji ${index + 1} z ${files.length}: ${name}…`;
            setRow(row, 'busy');

            let data;

            try {
                data = await send(file, index === files.length - 1);
            } catch (error) {
                data = { ok: false, error: 'Spojení se správou se přerušilo.' };
            }

            const item = data.ok ? (data.items ?? []).find((entry) => entry.file === file) : null;

            if (item?.status === 'updated') {
                updated++;
                setRow(row, 'done', item.to ?? '');
            } else if (item?.status === 'up_to_date') {
                setRow(row, 'done');
            } else {
                const why = item?.message || data.error || 'neznámá chyba';
                failed.push(`${name} (${why})`);
                setRow(row, 'failed', why);
            }

            fill.style.width = `${Math.round(((index + 1) / files.length) * 100)}%`;
        }

        progress.classList.add('plugin-progress--done');
        text.textContent = `Hotovo: aktualizováno ${updated} z ${files.length}.${failed.length > 0 ? ` Nepodařilo se: ${failed.join(' · ')}.` : ''} `;

        const reload = document.createElement('a');
        reload.href = window.location.href;
        reload.textContent = 'Načíst stránku znovu';
        text.append(reload);

        lock(false);
        running = false;
    };

    // Zpět v prohlížeči může vrátit stránku z paměti i s točícím se
    // kolečkem po odeslání — pak ji raději načíst znovu s čerstvými daty.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && form.getAttribute('aria-busy') === 'true') {
            window.location.reload();
        }
    });

    form.addEventListener('submit', (event) => {
        const submitter = event.submitter;

        if (running) {
            event.preventDefault();
            return;
        }

        if (submitter?.hasAttribute('formaction')) {
            // Tlačítko se nevypíná — vypnuté by z dat vypadlo i se svým pluginem.
            running = true;
            setIcon(submitter, 'refresh', 'icon--spin');
            submitter.closest('[data-plugin-row]')?.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            progress.hidden = false;
            progress.classList.remove('plugin-progress--done');
            fill.style.width = '';
            progress.classList.add('plugin-progress--indeterminate');
            text.textContent = submitter.dataset.busyText ?? 'Pracuji…';
            return;
        }

        event.preventDefault();

        const files = submitter?.name === 'plugin'
            ? [submitter.value]
            : [...form.querySelectorAll('input[name="plugins[]"]:checked')].map((input) => input.value);

        if (files.length === 0) {
            progress.hidden = false;
            text.textContent = 'Vyberte zaškrtnutím pluginy, které se mají aktualizovat.';
            return;
        }

        run(files);
    });
}
