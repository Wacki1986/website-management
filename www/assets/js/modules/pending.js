/**
 * Formuláře, jejichž odeslání trvá (aktualizace pluginů čeká na web
 * klienta i minutu): stisknuté tlačítko ukáže `data-pending-label`
 * a druhé odeslání se zahodí — dvojklik nespustí akci dvakrát.
 *
 * Velké akce nad celým webem („Zkontrolovat teď", aktualizace WordPressu)
 * mají navíc `data-pending-overlay` (nadpis) a `data-pending-note` (věta
 * pod ním): přes celou stránku se ukáže okno s kolečkem. Server odpoví
 * až po skončení akce a do té doby by jen točení v liště prohlížeče
 * vypadalo, že se nic neděje — a klikat mezitím jinam nemá smysl.
 *
 * Tlačítko se nevypíná atributem `disabled`: vypnuté tlačítko by
 * z odesílaných dat vypadlo i se svým `name`/`value` (řádkové
 * „Aktualizovat" nese právě tak svůj plugin).
 */
export function initPending() {
    document.querySelectorAll('form[data-pending]').forEach((form) => {
        let sent = false;

        form.addEventListener('submit', (event) => {
            if (sent) {
                event.preventDefault();
                return;
            }

            sent = true;
            const button = event.submitter;

            if (button?.dataset.pendingLabel) {
                button.textContent = button.dataset.pendingLabel;
                button.setAttribute('aria-busy', 'true');
            }

            form.setAttribute('aria-busy', 'true');

            if (form.dataset.pendingOverlay) {
                showOverlay(form.dataset.pendingOverlay, form.dataset.pendingNote ?? '');
            }
        });

        // Návrat šipkou Zpět vrátí stránku z paměti prohlížeče i s oknem
        // a zamčeným formulářem — musí jít kliknout znovu.
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                sent = false;
                form.removeAttribute('aria-busy');
                document.querySelectorAll('.modal--busy').forEach((dialog) => dialog.remove());
            }
        });
    });
}

/**
 * Modální `<dialog>`: stránka pod ním je neaktivní (nejde na nic kliknout
 * ani se na nic dostat tabulátorem) a čtečka obrazovky přečte nadpis.
 */
function showOverlay(title, note) {
    const dialog = document.createElement('dialog');
    dialog.className = 'modal modal--busy';
    dialog.setAttribute('aria-labelledby', 'pending-overlay-title');

    const icon = document.createElement('span');
    icon.className = 'icon icon--spin modal__spinner';
    icon.setAttribute('aria-hidden', 'true');
    icon.style.webkitMask = 'var(--icon-refresh) center/contain no-repeat';
    icon.style.mask = 'var(--icon-refresh) center/contain no-repeat';

    const heading = document.createElement('div');
    heading.className = 'card__title modal__title';
    heading.id = 'pending-overlay-title';
    heading.textContent = title;

    const text = document.createElement('div');
    text.className = 'card__note';
    text.textContent = note;

    const body = document.createElement('div');
    body.className = 'modal__body';
    body.append(icon, heading, text);
    dialog.append(body);

    // Esc by okno zavřel, ale akce na serveru běží dál — jen by zmizela
    // informace, že se čeká.
    dialog.addEventListener('cancel', (event) => event.preventDefault());

    document.body.append(dialog);
    dialog.showModal();
}
