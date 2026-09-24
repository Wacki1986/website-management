/**
 * Editor šablony reportu (Nastavení → Šablona reportu): texty se upravují
 * kliknutím přímo v náhledu e-mailu.
 *
 * Upravitelné texty mají `data-tpl="klíč"`. Kliknutí otevře pole s textem
 * šablony (i se značkami `{web}`…), pod ním tlačítka značek a „Výchozí
 * text". Enter (u víceřádkových Ctrl+Enter) nebo kliknutí jinam text
 * potvrdí a náhled se hned překreslí se skutečnými údaji; Esc úpravu
 * zruší. Rozpracované texty drží skrytá pole formuláře `template-form`,
 * ukládá se až tlačítkem v liště.
 *
 * Sekce e-mailu (`data-tpl-section`) mají po najetí myší štítek se
 * šipkami — posun přesune řádek v náhledu a zapíše pořadí do skrytého
 * pole `section_order`.
 */
export function initReportTemplate() {
    const root = document.querySelector('[data-template-editor]');

    if (!root) {
        return;
    }

    const config = JSON.parse(root.dataset.config);
    const texts = { ...config.texts };
    const status = root.querySelector('[data-template-status]');
    const save = root.querySelector('[data-template-save]');
    const discard = root.querySelector('[data-template-discard]');
    const input = (key) => root.querySelector(`[data-template-input="${key}"]`);
    const orderInput = root.querySelector('[data-template-order]');
    let editing = null;

    const escape = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));

    // Stejné vykreslení jako ReportRenderer: dosadit značky, escapovat,
    // zalomení řádků, velká písmena u nadpisu poznámky, adresa tučně.
    const render = (key) => {
        let text = texts[key];

        for (const [code, value] of Object.entries(config.values)) {
            text = text.split(code).join(value);
        }

        if (config.upper.includes(key)) {
            text = text.toLocaleUpperCase('cs');
        }

        let html = escape(text);

        if (config.fields[key].rows > 1) {
            html = html.replace(/\n/g, '<br>\n');
        }

        const bold = config.bold[key];

        if (bold) {
            html = html.split(escape(bold)).join(`<b style="font-weight:600;color:#14162b;">${escape(bold)}</b>`);
        }

        return html;
    };

    // Pořadí sekcí podle náhledu; sekce, které vzor nevykreslil, zůstávají
    // na konci v uloženém pořadí.
    const currentOrder = () => {
        const shown = [...root.querySelectorAll('[data-tpl-section]')].map((tr) => tr.dataset.tplSection);

        return [...shown, ...config.order.filter((key) => !shown.includes(key))];
    };

    const refresh = () => {
        const changed = Object.keys(texts).filter((key) => texts[key] !== config.saved[key]);
        const order = currentOrder();

        orderInput.value = order.join(',');

        if (order.join(',') !== config.order.join(',')) {
            changed.push('_order');
        }

        const rows = [...root.querySelectorAll('[data-tpl-section]')];
        rows.forEach((tr, index) => {
            tr.querySelector('[data-move="-1"]').disabled = index === 0;
            tr.querySelector('[data-move="1"]').disabled = index === rows.length - 1;
        });

        root.querySelectorAll('[data-tpl]').forEach((el) => {
            const key = el.dataset.tpl;
            el.toggleAttribute('data-tpl-custom', texts[key] !== config.fields[key].default);
            el.toggleAttribute('data-tpl-dirty', changed.includes(key));
        });

        status.textContent = changed.length === 0 ? 'Bez změn' : `Neuložené změny: ${changed.length}`;
        status.classList.toggle('text-warning', changed.length > 0);
        save.disabled = changed.length === 0;
        discard.hidden = changed.length === 0;
    };

    const set = (key, value) => {
        // Prázdný text = výchozí znění (stejně jako při uložení na serveru).
        texts[key] = value.trim() === '' ? config.fields[key].default : value;
        input(key).value = texts[key];
        root.querySelectorAll(`[data-tpl="${key}"]`).forEach((el) => { el.innerHTML = render(key); });
        refresh();
    };

    const close = (commit) => {
        if (!editing) {
            return;
        }

        const { el, key, field } = editing;
        editing = null;
        el.removeAttribute('data-tpl-editing');

        if (commit) {
            set(key, config.fields[key].rows > 1 ? field.value : field.value.replace(/\s+/g, ' '));
        } else {
            el.innerHTML = render(key);
        }
    };

    const open = (el) => {
        const key = el.dataset.tpl;
        const meta = config.fields[key];
        const multiline = meta.rows > 1;
        const field = document.createElement('textarea');
        const panel = document.createElement('span');
        const tools = document.createElement('span');

        panel.className = 'tpl-editor';
        field.className = 'tpl-editor__field';
        field.value = texts[key];
        field.maxLength = meta.max;
        field.rows = multiline ? Math.max(meta.rows, 2) : 1;
        field.setAttribute('aria-label', meta.label);
        tools.className = 'tpl-editor__tools';

        for (const [code, meaning] of Object.entries(config.placeholders)) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'tpl-editor__chip';
            chip.textContent = code;
            chip.title = `Vložit ${meaning}`;
            chip.dataset.insert = code;
            tools.append(chip);
        }

        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'tpl-editor__chip tpl-editor__chip--reset';
        reset.textContent = 'Výchozí text';
        reset.title = meta.default;
        reset.dataset.reset = '1';
        tools.append(reset);

        const hint = document.createElement('span');
        hint.className = 'tpl-editor__hint';
        hint.textContent = `${meta.label} · ${multiline ? 'Ctrl+Enter' : 'Enter'} uloží, Esc zruší`;
        tools.append(hint);

        panel.append(field, tools);
        el.innerHTML = '';
        el.append(panel);
        el.setAttribute('data-tpl-editing', '');
        editing = { el, key, field };

        const grow = () => {
            field.style.height = 'auto';
            field.style.height = `${field.scrollHeight}px`;
        };

        field.addEventListener('input', grow);
        field.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Enter' && (!multiline || event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                close(true);
            }
        });

        // Tlačítka v panelu nesmí pole zavřít (blur) dřív, než udělají svoje.
        tools.addEventListener('mousedown', (event) => event.preventDefault());
        tools.addEventListener('click', (event) => {
            const button = event.target.closest('button');

            if (!button) {
                return;
            }

            if (button.dataset.reset) {
                field.value = meta.default;
            } else {
                field.setRangeText(button.dataset.insert, field.selectionStart, field.selectionEnd, 'end');
            }

            field.focus();
            grow();
        });

        field.addEventListener('blur', () => close(true));
        field.focus();
        field.setSelectionRange(field.value.length, field.value.length);
        grow();
    };

    root.addEventListener('click', (event) => {
        const el = event.target.closest('[data-tpl]');

        // Odkazy v náhledu (tlačítka e-mailu) se v editoru neotevírají.
        if (event.target.closest('[data-template-sheet] a, .template-subjects a')) {
            event.preventDefault();
        }

        if (!el || el.hasAttribute('data-tpl-editing')) {
            return;
        }

        event.preventDefault();
        close(true);
        open(el);
    });

    // Klávesnice: texty jdou projít tabulátorem a otevřít Enterem.
    root.querySelectorAll('[data-tpl]').forEach((el) => {
        el.tabIndex = 0;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', `Upravit: ${config.fields[el.dataset.tpl].label}`);
    });

    root.addEventListener('keydown', (event) => {
        const el = event.target;

        if (event.key === 'Enter' && el.matches?.('[data-tpl]:not([data-tpl-editing])')) {
            event.preventDefault();
            open(el);
        }
    });

    // Štítek sekce se šipkami, vložený do její buňky (každá sekce je jeden <tr>).
    root.querySelectorAll('[data-tpl-section]').forEach((tr) => {
        const label = config.sections[tr.dataset.tplSection] ?? tr.dataset.tplSection;
        const bar = document.createElement('span');

        bar.className = 'tpl-move';
        bar.innerHTML = `<span class="tpl-move__label">${escape(label)}</span>`
            + `<button type="button" class="tpl-move__button" data-move="-1" title="Posunout výš" aria-label="Posunout sekci ${escape(label)} výš">↑</button>`
            + `<button type="button" class="tpl-move__button" data-move="1" title="Posunout níž" aria-label="Posunout sekci ${escape(label)} níž">↓</button>`;
        tr.firstElementChild.classList.add('tpl-section');
        tr.firstElementChild.prepend(bar);
    });

    const move = (tr, direction) => {
        const sibling = (el) => (direction < 0 ? el.previousElementSibling : el.nextElementSibling);
        let target = sibling(tr);

        while (target && !target.hasAttribute('data-tpl-section')) {
            target = sibling(target);
        }

        if (!target) {
            return;
        }

        target.parentNode.insertBefore(tr, direction < 0 ? target : target.nextElementSibling);
        refresh();
    };

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-move]');

        if (button) {
            event.preventDefault();
            move(button.closest('[data-tpl-section]'), Number(button.dataset.move));
            button.focus();
        }
    });

    discard.addEventListener('click', () => {
        close(false);
        Object.keys(texts).forEach((key) => set(key, config.saved[key]));

        // Pořadí zpět podle uloženého: postupně přesunout na konec.
        const rows = new Map([...root.querySelectorAll('[data-tpl-section]')].map((tr) => [tr.dataset.tplSection, tr]));
        const anchor = [...rows.values()].at(-1)?.nextElementSibling ?? null;

        config.order.forEach((key) => {
            const tr = rows.get(key);

            if (tr) {
                tr.parentNode.insertBefore(tr, anchor);
            }
        });

        refresh();
    });

    root.querySelector('#template-form').addEventListener('submit', () => {
        close(true);
        window.removeEventListener('beforeunload', warn);
    });

    // „Vrátit výchozí texty“ (dialog mimo editor) změny zahazuje vědomě.
    document.querySelector('#template-reset form')?.addEventListener('submit', () => window.removeEventListener('beforeunload', warn));

    const warn = (event) => {
        if (!save.disabled) {
            event.preventDefault();
        }
    };

    window.addEventListener('beforeunload', warn);
    refresh();
}
