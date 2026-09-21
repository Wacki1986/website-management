/**
 * Kopírování do schránky — tlačítka s `data-copy="hodnota"`.
 *
 * Bez skriptu se tlačítko vůbec nevykresluje jako funkční prvek nikde,
 * kde by bez něj nešlo hodnotu získat jinak (adresa jde označit myší).
 * Po úspěchu se ikona přepne na fajfku (třída `is-done`).
 */
export function initCopy() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', () => {
            navigator.clipboard.writeText(button.dataset.copy ?? '').then(() => {
                button.classList.add('is-done');
                window.setTimeout(() => button.classList.remove('is-done'), 1600);
            }).catch(() => {});
        });
    });
}
