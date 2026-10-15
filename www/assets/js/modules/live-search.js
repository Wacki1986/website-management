/**
 * Hledání při psaní — formulář s `data-live-search` (weby, projekty,
 * klienti, dashboard, reporty, pluginy webu).
 *
 * Dva režimy:
 *
 * `data-live-search` (bez hodnoty) — po zadání aspoň MIN_LENGTH znaků
 * (nebo po smazání pole) a krátké pauze načte stejnou stránku s parametry
 * formuláře a vymění v ní jen bloky označené `data-live-region="…"` —
 * pole hledání se nepřekresluje, takže kurzor ani rozepsaný text
 * nezmizí. Server se nemění: vrací celou stránku jako při obyčejném
 * odeslání a filtry (stav, klient, seskupení) zůstávají v odkazech,
 * které přijdou v nových blocích. Hodí se jen tam, kde vyměňované bloky
 * nemají na sobě navázané skripty (odkazy, potvrzovací okna přes
 * `data-confirm` — ta poslouchají na celém dokumentu).
 *
 * `data-live-search="local"` — stránka už má celý seznam a jen se skrývají
 * řádky `[data-live-item]`, jejichž `data-live-text` hledaný text
 * neobsahuje; `[data-live-empty]` se ukáže, když nezbyde žádný. Pro
 * tabulky se zaškrtávátky a rozběhnutými akcemi (pluginy): výměna bloku
 * by zahodila výběr i skripty navázané na řádky.
 *
 * Adresa v liště se přepíše (`replaceState`), aby obnovení stránky nebo
 * odkaz z ní ukázaly totéž hledání. Enter hledá hned a bez limitu znaků.
 */
const MIN_LENGTH = 2;
const DELAY_MS = 300;

export function initLiveSearch() {
    document.querySelectorAll('form[data-live-search]').forEach((form) => {
        const input = form.querySelector('input[type="search"]');
        const scope = form.closest('section') ?? document;

        if (!input) {
            return;
        }

        if (form.dataset.liveSearch === 'local') {
            initLocal(form, input, scope);
            return;
        }

        let timer = null;
        let controller = null;
        let lastQuery = input.value.trim();

        const run = async (force = false) => {
            const query = input.value.trim();

            // Jeden znak ještě nehledá (vrátil by skoro všechno); prázdné
            // pole vrátí celý seznam, ale jen když předtím něco filtrovalo.
            if (!force && (query === lastQuery || (query !== '' && query.length < MIN_LENGTH))) {
                return;
            }

            lastQuery = query;
            controller?.abort();
            const own = controller = new AbortController();

            // Prázdná pole (q, „Klient · všichni") do adresy nepatří.
            const params = new URLSearchParams([...new FormData(form)].filter(([, value]) => value !== ''));
            const search = params.toString();
            const url = form.action + (search !== '' ? '?' + search : '');
            const regions = scope.querySelectorAll('[data-live-region]');

            regions.forEach((region) => region.setAttribute('aria-busy', 'true'));

            try {
                const response = await fetch(url, { signal: own.signal, headers: { Accept: 'text/html' } });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const page = new DOMParser().parseFromString(await response.text(), 'text/html');

                regions.forEach((region) => {
                    const fresh = page.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
                    region.replaceWith(fresh ?? region);
                });

                history.replaceState(null, '', url);
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }

                // Nepovedlo se (odhlášení, chyba serveru) — obyčejné odeslání
                // ukáže, co se děje, třeba přihlašovací stránku.
                form.submit();
            } finally {
                // Zrušený požadavek nesmí sundat „načítá se" tomu novějšímu.
                if (controller === own) {
                    regions.forEach((region) => region.removeAttribute('aria-busy'));
                }
            }
        };

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(run, DELAY_MS);
        });

        // Enter v poli. Výběr klienta volá form.submit(), který tuhle
        // událost nevyvolá — ten dál načte celou stránku.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(timer);
            run(true);
        });
    });
}

/**
 * Bez diakritiky a velkých písmen — „wpml" najde „WPML", „sablona"
 * najde „Šablona", stejně jako hledání v databázi.
 */
function normalize(text) {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLocaleLowerCase('cs');
}

function initLocal(form, input, scope) {
    const items = [...scope.querySelectorAll('[data-live-item]')].map((element) => ({
        element,
        text: normalize(element.dataset.liveText ?? element.textContent),
    }));
    const empty = scope.querySelector('[data-live-empty]');

    const run = () => {
        const query = normalize(input.value.trim());
        let shown = 0;

        items.forEach(({ element, text }) => {
            element.hidden = query !== '' && !text.includes(query);
            shown += element.hidden ? 0 : 1;
        });

        if (empty) {
            empty.hidden = shown > 0;
        }

        const url = new URL(window.location.href);

        if (input.value.trim() === '') {
            url.searchParams.delete(input.name);
        } else {
            url.searchParams.set(input.name, input.value.trim());
        }

        history.replaceState(null, '', url);
    };

    // Seznam je na stránce celý a filtruje se bez serveru — není na co čekat.
    input.addEventListener('input', run);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        run();
    });
}
