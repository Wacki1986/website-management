/**
 * Výběr řádků v tabulce (pluginy, alerty) — `.checkbox` z návrhu.
 *
 * Dva druhy položek:
 *  - `<button data-selection-item>` (pluginy) — stav drží jen třída
 *    `checkbox--checked`; bez skriptu nemá funkci (v1 na výběr nic nenavazuje),
 *  - `<input type="checkbox" data-selection-item>` uvnitř `<label class="checkbox">`
 *    (alerty) — skutečné pole formuláře, odešle se i bez skriptu.
 *
 * Skript doplňuje hlavičkové zaškrtávátko (vše / částečný výběr), pruh
 * hromadných akcí `[data-selection-bar]` s počtem a tlačítko
 * `[data-selection-button]` s počtem v textu.
 */
export function initSelection() {
    document.querySelectorAll('[data-selection]').forEach((table) => {
        const all = table.querySelector('[data-selection-all]');
        const items = [...table.querySelectorAll('[data-selection-item]')];
        const scope = table.closest('.card') ?? table;
        const button = scope.querySelector('[data-selection-button]');
        const bar = scope.querySelector('[data-selection-bar]');
        const note = scope.querySelector('[data-selection-note]');
        const baseLabel = button?.textContent.replace(/\s*\(\d+\)\s*$/, '') ?? '';

        const isChecked = (item) => item instanceof HTMLInputElement ? item.checked : item.classList.contains('checkbox--checked');

        const setChecked = (item, checked) => {
            if (item instanceof HTMLInputElement) {
                item.checked = checked;
                item.closest('.checkbox')?.classList.toggle('checkbox--checked', checked);
            } else {
                item.classList.toggle('checkbox--checked', checked);
                item.setAttribute('aria-checked', checked ? 'true' : 'false');
            }
        };

        const refresh = () => {
            const selected = items.filter(isChecked).length;

            if (all) {
                all.classList.toggle('checkbox--checked', selected === items.length && items.length > 0);
                all.classList.toggle('checkbox--indeterminate', selected > 0 && selected < items.length);
                all.setAttribute('aria-checked', selected === 0 ? 'false' : (selected === items.length ? 'true' : 'mixed'));
            }

            if (button) {
                button.textContent = selected > 0 ? `${baseLabel} (${selected})` : baseLabel;
            }

            if (bar) {
                bar.hidden = selected === 0;
            }

            if (note) {
                const word = selected === 1 ? 'alert' : (selected >= 2 && selected <= 4 ? 'alerty' : 'alertů');
                note.textContent = `Vybráno ${selected} ${word}`;
            }
        };

        items.forEach((item) => {
            const event = item instanceof HTMLInputElement ? 'change' : 'click';
            item.addEventListener(event, () => {
                if (!(item instanceof HTMLInputElement)) {
                    setChecked(item, !isChecked(item));
                } else {
                    item.closest('.checkbox')?.classList.toggle('checkbox--checked', item.checked);
                }
                refresh();
            });
        });

        all?.addEventListener('click', () => {
            const everything = items.every(isChecked);
            items.forEach((item) => setChecked(item, !everything));
            refresh();
        });

        scope.querySelector('[data-selection-clear]')?.addEventListener('click', () => {
            items.forEach((item) => setChecked(item, false));
            refresh();
        });

        refresh();
    });
}
