/**
 * Náhled barvy aplikace v Základním nastavení (`form[data-accent-picker]`).
 *
 * Kliknutím na barvu se celá stránka hned přebarví (`<html data-accent>`,
 * styly v app/_accents.scss), ať jde vybírat okem. Uloží se až tlačítkem —
 * odchod bez uložení vrátí uloženou barvu, protože ji server vypíše znovu.
 */
export function initAccentPreview() {
    document.querySelectorAll('form[data-accent-picker]').forEach((form) => {
        form.addEventListener('change', (event) => {
            const input = event.target;

            if (input instanceof HTMLInputElement && input.name === 'accent' && input.checked) {
                document.documentElement.dataset.accent = input.value;
            }
        });
    });
}
