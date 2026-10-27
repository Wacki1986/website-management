/**
 * Zamčené pole, které jde odemknout zaškrtnutím potvrzení —
 * `<input type="checkbox" data-unlocks="jmeno_pole">` ve stejném formuláři.
 *
 * Použití: složka pluginu v Základním nastavení. Po napojení webů je pole
 * jen ke čtení; zaškrtnutím „Rozumím, weby se odpojí" se dá přepsat.
 * Server potvrzení kontroluje sám — skript jen ušetří zbytečný pokus.
 */
export function initUnlockField() {
    document.querySelectorAll('input[type="checkbox"][data-unlocks]').forEach((checkbox) => {
        const field = checkbox.form?.elements.namedItem(checkbox.dataset.unlocks);

        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        const sync = () => {
            field.readOnly = !checkbox.checked;

            if (checkbox.checked) {
                field.focus();
            }
        };

        checkbox.addEventListener('change', sync);
        field.readOnly = !checkbox.checked;
    });
}
