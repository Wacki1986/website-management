/**
 * Výběr existující kontaktní osoby — `select[data-contact-pick]` ve
 * formuláři klienta (hlavní kontakt) a v Dalších kontaktech v detailu
 * klienta.
 *
 * `data-contacts` nese JSON {id: {first_name, last_name, email, phone,
 * clients: {id klienta: název}}} (`ContactController::pickData()`),
 * `data-contact-client` id klienta, na jehož stránce výběr je — ten se
 * v upozornění „osoba je i u…" nevypisuje.
 *
 * Dva režimy:
 *
 * `edit` (formulář klienta) — výběr vyplní jméno, e-mail a telefon a pole
 * zůstanou upravitelná: uložení přepíše osobu, takže změna platí u všech
 * jejích klientů. Proto upozornění, u koho dalšího osoba je. Volba „Nová
 * osoba" pole vyprázdní.
 *
 * `link` (Další kontakty) — vybraná osoba se jen připojí, její údaje se
 * tady neupravují: pole osoby (obal `.contact-person-field`) se schovají
 * a vypnou, aby se neodeslala. Zůstane jen role u tohoto klienta.
 */
const FIELDS = ['first_name', 'last_name', 'email', 'phone'];

export function initContactPick() {
    document.querySelectorAll('select[data-contact-pick]').forEach((select) => {
        const form = select.form;
        const mode = select.dataset.contactPick;
        const ownClient = select.dataset.contactClient ?? '';
        const note = form.querySelector('[data-contact-shared]');
        let contacts = {};

        try {
            contacts = JSON.parse(select.dataset.contacts ?? '{}');
        } catch {
            return; // bez dat by výběr jen mátl — pole zůstanou na ruční vyplnění
        }

        const update = (fill) => {
            const person = contacts[select.value] ?? null;

            if (mode === 'link') {
                form.querySelectorAll('.contact-person-field').forEach((wrap) => {
                    wrap.hidden = person !== null;
                    wrap.querySelectorAll('input').forEach((input) => { input.disabled = person !== null; });
                });
            } else if (fill) {
                FIELDS.forEach((name) => {
                    const input = form.elements.namedItem(name);

                    if (input) {
                        input.value = person ? person[name] : '';
                    }
                });
            }

            if (!note) {
                return;
            }

            const others = person
                ? Object.entries(person.clients).filter(([id]) => id !== ownClient).map(([, name]) => name)
                : [];

            note.hidden = others.length === 0;
            // Bez tečky za výčtem: názvy firem často končí „s.r.o.".
            note.textContent = others.length === 0 ? '' : mode === 'link'
                ? `Osoba je i u: ${others.join(', ')}`
                : `Změna jména, e-mailu nebo telefonu se projeví i u: ${others.join(', ')}`;
        };

        select.addEventListener('change', () => update(true));
        // Po načtení jen ukázat stav — pole můžou nést rozepsané hodnoty
        // z neúspěšného odeslání a ty se nepřepisují.
        update(false);
    });
}
