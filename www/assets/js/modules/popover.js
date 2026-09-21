/**
 * Rozbalovací panely na `<details data-popover>` — patička výpisu, výběr
 * data a času (picker).
 *
 * Rozbalení řeší nativní `<details>`, takže bez JavaScriptu všechno funguje
 * (zavře se druhým kliknutím na tlačítko). Tady se doplňuje jen to, co
 * `<details>` neumí: zavření kliknutím vedle a klávesou Escape (s návratem
 * ohniska na tlačítko). Zkrácená verze modulu z klientské aplikace —
 * bez přepočtu polohy, panel správy stojí v kartě bez vodorovného ořezu.
 */
export function initPopovers() {
    const open = () => document.querySelectorAll('details[data-popover][open]');

    /**
     * Kde stiskla myš — zjištěno **při stisku**, ne až při kliknutí.
     *
     * Panel, který si při kliknutí překreslí obsah (výběr data), stihne prvek
     * odpojit z DOMu dřív, než událost dobublá sem. `closest()` odpojeného
     * prvku je pak `null` — a panel by se zavřel po každém kliknutí dovnitř.
     * Přesně tak se choval výběr data: listování měsíců „nefungovalo",
     * protože se panel hned zavřel (tentýž nález řešila klientská aplikace).
     */
    let pressedIn = null;

    document.addEventListener('pointerdown', (event) => {
        pressedIn = event.target instanceof Element
            ? event.target.closest('details[data-popover]')
            : null;
    }, true);

    document.addEventListener('click', (event) => {
        // Prvek, který v DOMu zůstal, řekne pravdu sám; odpojený se pozná
        // podle toho, kde začal stisk. Klávesnice `pointerdown` nevyvolá,
        // proto se na něj nedá spolehnout jako na jediný zdroj.
        const inside = event.target instanceof Element && event.target.isConnected
            ? event.target.closest('details[data-popover]')
            : pressedIn;

        open().forEach((popover) => {
            // Zavírá se jen panel, do kterého se neklikalo — včetně vnořených
            // (picker uvnitř jiného panelu zavře jen sám sebe).
            if (!(inside && popover.contains(inside))) {
                popover.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        open().forEach((popover) => {
            popover.open = false;
            popover.querySelector('summary')?.focus();
        });
    });

    /**
     * Panel u spodního kraje okna se rozbalí nahoru (`data-popover-flip`).
     *
     * Nabídka u posledního řádku výpisu jinak přesahuje pod patičku a
     * stránka o ni povyroste — člověk klepne na tři tečky a musí sjet dolů
     * za tím, co si právě otevřel.
     *
     * Rozhoduje **skutečná výška panelu**, ne pořadí řádku: kolik má
     * nabídka položek, se liší instanci od instance (pozastavená má jiné
     * akce než běžící) a pravidlo „poslední tři řádky nahoru“ by u krátké
     * tabulky panel naopak vystrčilo nad obrazovku.
     *
     * Událost `toggle` nebublá, proto naslouchání v zachytávací fázi —
     * tam projde i k dokumentu.
     */
    document.addEventListener('toggle', (event) => {
        const details = event.target;

        if (!(details instanceof HTMLElement) || !details.matches('details[data-popover-flip]')) {
            return;
        }

        // Měří se vždy z výchozí polohy: se zapnutou třídou by panel visel
        // nahoře a odpověď by se při druhém otevření obrátila.
        details.classList.remove('is-up');

        const panel = details.querySelector(':scope > :not(summary)');

        if (!details.open || panel === null) {
            return;
        }

        const below = window.innerHeight - details.getBoundingClientRect().bottom;

        // 8 px rezerva, ať se panel nelepí na kraj okna.
        details.classList.toggle('is-up', panel.offsetHeight + 8 > below);
    }, true);
}
