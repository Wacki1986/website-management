/**
 * Náhled reportu: přepínač Počítač/Mobil bez načtení stránky, počítadlo
 * znaků poznámky, rychlé pilulky s předpřipravenými větami a automatické
 * uložení sekcí po přepnutí. Všechno funguje i bez skriptu — přepínač
 * jsou odkazy, sekce mají tlačítko Uložit.
 */
export function initReportPreview() {
    const sheet = document.querySelector('[data-preview-sheet]');
    const device = document.querySelector('[data-preview-device]');

    if (sheet && device) {
        device.addEventListener('click', (event) => {
            const link = event.target.closest('[data-device]');

            if (!link) {
                return;
            }

            event.preventDefault();
            sheet.classList.toggle('email-paper__sheet--mobile', link.dataset.device === 'mobile');
            device.querySelectorAll('[data-device]').forEach((item) => item.classList.toggle('segmented__item--active', item === link));
            history.replaceState(null, '', link.getAttribute('href'));
        });
    }

    const note = document.querySelector('[data-note]');
    const counter = document.querySelector('[data-note-counter]');

    if (note && counter) {
        note.addEventListener('input', () => {
            counter.textContent = String(note.value.length);
        });
    }

    const pills = document.querySelector('[data-note-pills]');

    if (note && pills && !note.disabled) {
        pills.hidden = false;
        pills.addEventListener('click', (event) => {
            const pill = event.target.closest('[data-note-add]');

            if (!pill) {
                return;
            }

            const max = Number(note.getAttribute('maxlength') || 400);
            const current = note.value.trim();
            const next = (current === '' ? '' : current + ' ') + pill.dataset.noteAdd;
            note.value = next.slice(0, max);
            note.dispatchEvent(new Event('input'));
            note.focus();
        });
    }

    document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
        const button = form.querySelector('[data-autosubmit-button]');

        if (button) {
            button.hidden = true;
        }

        form.addEventListener('change', () => form.submit());
    });
}
