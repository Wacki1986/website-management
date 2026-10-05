/**
 * Hledání při psaní — formulář s `data-live-search` (seznam webů).
 *
 * Po zadání aspoň MIN_LENGTH znaků (nebo po smazání pole) a krátké pauze
 * načte stejnou stránku s parametry formuláře a vymění v ní jen bloky
 * označené `data-live-region="…"` — pole hledání se nepřekresluje, takže
 * kurzor ani rozepsaný text nezmizí. Server se nemění: vrací celou
 * stránku jako při obyčejném odeslání a filtry (stav, klient, seskupení)
 * zůstávají v odkazech, které přijdou v nových blocích.
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
