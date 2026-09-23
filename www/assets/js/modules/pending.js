/**
 * Formuláře, jejichž odeslání trvá (aktualizace pluginů čeká na web
 * klienta i minutu): stisknuté tlačítko ukáže `data-pending-label`
 * a druhé odeslání se zahodí — dvojklik nespustí akci dvakrát.
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
        });
    });
}
