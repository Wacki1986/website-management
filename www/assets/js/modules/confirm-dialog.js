/**
 * Potvrzení v modálním okně místo samostatné stránky.
 *
 * Odkaz `<a href="…/potvrzeni" data-confirm="id-dialogu">` bez skriptu
 * vede na potvrzovací stránku; se skriptem otevře `<dialog id="…">`
 * a doplní do něj údaje z řádku:
 *   data-confirm-value → skryté pole `[data-confirm-value]` ve formuláři dialogu
 *   data-confirm-title → text prvku `[data-confirm-title]`
 *   data-confirm-note  → text prvku `[data-confirm-note]`
 *   data-confirm-action → `action` formuláře v dialogu (jedno okno pro víc řádků)
 * Zavírá tlačítko `[data-dialog-close]`, Esc a klik mimo okno.
 */
export function initConfirmDialogs() {
    if (typeof HTMLDialogElement !== 'function') {
        return; // starý prohlížeč: zůstane potvrzovací stránka
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('a[data-confirm]');
        const dialog = trigger && document.getElementById(trigger.dataset.confirm);

        if (!dialog) {
            return;
        }

        event.preventDefault();

        const { confirmValue, confirmTitle, confirmNote, confirmAction } = trigger.dataset;
        const form = dialog.querySelector('form');

        if (form && confirmAction !== undefined) form.action = confirmAction;
        const field = dialog.querySelector('[data-confirm-value]');
        const title = dialog.querySelector('[data-confirm-title]');
        const note = dialog.querySelector('[data-confirm-note]');

        if (field && confirmValue !== undefined) field.value = confirmValue;
        if (title && confirmTitle !== undefined) title.textContent = confirmTitle;
        if (note && confirmNote !== undefined) note.textContent = confirmNote;

        dialog.showModal();
    });

    document.querySelectorAll('dialog.modal').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            // Klik na podklad: cíl je sám dialog, ne jeho obsah.
            if (event.target === dialog || event.target.closest('[data-dialog-close]')) {
                dialog.close();
            }
        });
    });
}
