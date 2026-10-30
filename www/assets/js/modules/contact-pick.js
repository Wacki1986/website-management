/**
 * Výběr existující kontaktní osoby s hledáním při psaní — `.contact-pick`
 * (partial `contact-pick`) ve formuláři klienta (hlavní kontakt)
 * a v Dalších kontaktech v detailu klienta.
 *
 * `data-contacts` nese JSON seznam [{id, name, label, first_name, last_name,
 * email, phone, clients: {id klienta: název}}] seřazený podle příjmení
 * (`ContactController::pickData()`), `data-contact-client` id klienta,
 * na jehož stránce výběr je — ten se v upozornění „osoba je i u…"
 * nevypisuje. Vybraná osoba jde do skrytého `contact_id`; prázdné =
 * nová osoba z polí pod výběrem.
 *
 * Hledá se ve jméně (v libovolném pořadí slov), e-mailu, telefonu
 * i firmách, bez ohledu na diakritiku a velikost písmen. Psaní samo nic
 * nevybírá — osoba se vybere až klikem nebo Enterem; po odchodu z pole
 * se v něm zase ukáže vybraná osoba (nebo zůstane prázdné).
 *
 * Dva režimy:
 *
 * `edit` (formulář klienta) — výběr vyplní jméno, e-mail a telefon a pole
 * zůstanou upravitelná: uložení přepíše osobu, takže změna platí u všech
 * jejích klientů. Proto upozornění, u koho dalšího osoba je. Zrušení
 * výběru (křížek) pole vyprázdní.
 *
 * `link` (Další kontakty) — vybraná osoba se jen připojí, její údaje se
 * tady neupravují: pole osoby (obal `.contact-person-field`) se schovají
 * a vypnou, aby se neodeslala. Zůstane jen role u tohoto klienta.
 */
const FIELDS = ['first_name', 'last_name', 'email', 'phone'];
const MAX_SHOWN = 8;

/** „Dobrá" i „dobra" najdou „Dobrá"; mezery v telefonu se nepočítají. */
const fold = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export function initContactPick() {
    document.querySelectorAll('.contact-pick[data-contact-pick]').forEach(initPick);
}

function initPick(root) {
    const form = root.closest('form');
    const mode = root.dataset.contactPick;
    const ownClient = root.dataset.contactClient ?? '';
    const hidden = root.querySelector('input[type="hidden"]');
    const input = root.querySelector('.contact-pick__input');
    const clear = root.querySelector('.contact-pick__clear');
    const list = root.querySelector('.contact-pick__list');
    const note = form.querySelector('[data-contact-shared]');
    let people = [];

    try {
        people = JSON.parse(root.dataset.contacts ?? '[]');
    } catch {
        return; // bez dat by výběr jen mátl — pole zůstanou na ruční vyplnění
    }

    people.forEach((person) => {
        person.haystack = fold([person.name, person.email, person.phone.replace(/\s/g, ''), person.phone, ...Object.values(person.clients)].join(' '));
    });

    const byId = new Map(people.map((person) => [person.id, person]));
    let shown = [];
    let active = -1;

    const selected = () => byId.get(hidden.value) ?? null;

    const others = (person) => Object.entries(person.clients).filter(([id]) => id !== ownClient).map(([, name]) => name);

    /** Pole osoby a upozornění podle vybrané osoby; `fill` = přepsat pole (edit). */
    const apply = (fill) => {
        const person = selected();

        if (mode === 'link') {
            form.querySelectorAll('.contact-person-field').forEach((wrap) => {
                wrap.hidden = person !== null;
                wrap.querySelectorAll('input').forEach((field) => { field.disabled = person !== null; });
            });
        } else if (fill) {
            FIELDS.forEach((name) => {
                const field = form.elements.namedItem(name);

                if (field) {
                    field.value = person ? person[name] : '';
                }
            });
        }

        clear.hidden = person === null;
        input.value = person ? person.label : '';

        if (note) {
            const names = person ? others(person) : [];
            note.hidden = names.length === 0;
            // Bez tečky za výčtem: názvy firem často končí „s.r.o.".
            note.textContent = names.length === 0 ? '' : mode === 'link'
                ? `Osoba je i u: ${names.join(', ')}`
                : `Změna jména, e-mailu nebo telefonu se projeví i u: ${names.join(', ')}`;
        }
    };

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const highlight = (index) => {
        active = index;
        list.querySelectorAll('[role="option"]').forEach((item, i) => {
            item.setAttribute('aria-selected', String(i === index));

            if (i === index) {
                input.setAttribute('aria-activedescendant', item.id);
                item.scrollIntoView({ block: 'nearest' });
            }
        });
    };

    const render = () => {
        // Dokud v poli stojí popisek vybrané osoby, nabízí se všichni.
        const query = input.value === (selected()?.label ?? '') ? '' : fold(input.value.trim());
        const words = query.split(/\s+/).filter(Boolean);
        const matches = people.filter((person) => words.every((word) => person.haystack.includes(word)));

        shown = matches.slice(0, MAX_SHOWN);
        list.replaceChildren();

        shown.forEach((person, i) => {
            const item = document.createElement('li');
            item.id = `${input.id}-option-${i}`;
            item.className = 'contact-pick__option';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');

            const name = document.createElement('span');
            name.className = 'contact-pick__name';
            name.textContent = person.name;

            const meta = document.createElement('span');
            meta.className = 'contact-pick__meta';
            meta.textContent = [person.email, person.phone, Object.values(person.clients).join(', ')].filter(Boolean).join(' · ');

            item.append(name, meta);
            item.addEventListener('click', () => choose(person));
            list.append(item);
        });

        const info = matches.length === 0
            ? 'Nikdo neodpovídá — novou osobu vyplňte níže.'
            : matches.length > shown.length ? `… a ${matches.length - shown.length} dalších, zpřesněte hledání` : '';

        if (info !== '') {
            const empty = document.createElement('li');
            empty.className = 'contact-pick__info';
            empty.textContent = info;
            list.append(empty);
        }

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        highlight(words.length > 0 && shown.length > 0 ? 0 : -1);
    };

    const choose = (person) => {
        hidden.value = person ? person.id : '';
        close();
        apply(true);
    };

    // Klik do nabídky nesmí vzít poli fokus — jinak by ji `blur` zavřel
    // dřív, než klik doběhne.
    list.addEventListener('mousedown', (event) => event.preventDefault());

    input.addEventListener('focus', () => {
        input.select();
        render();
    });
    input.addEventListener('input', render);
    input.addEventListener('blur', () => {
        close();
        input.value = selected()?.label ?? '';
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (list.hidden) {
                render();
                return;
            }

            const step = event.key === 'ArrowDown' ? 1 : -1;
            highlight(Math.max(0, Math.min(shown.length - 1, active + step)));
        } else if (event.key === 'Enter' && !list.hidden) {
            // Enter v otevřené nabídce vybírá, neodesílá formulář.
            event.preventDefault();

            if (active >= 0) {
                choose(shown[active]);
            }
        } else if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            close();
            input.value = selected()?.label ?? '';
        }
    });

    clear.addEventListener('click', () => {
        choose(null);
        input.focus();
    });

    // Po načtení jen ukázat stav — pole můžou nést rozepsané hodnoty
    // z neúspěšného odeslání a ty se nepřepisují.
    apply(false);
}
