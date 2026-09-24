/**
 * Bublina s popisem (tooltip) pro celou aplikaci.
 *
 * Šablony píšou obyčejný `title` (případně `data-tooltip`). Nativní title
 * se ukáže až po chvíli a vypadá podle systému — skript ho při najetí
 * myší nebo fokusu převezme (`title` → `data-tooltip`, nativní bublina se
 * tak neukáže) a hned ukáže vlastní bublinu nad prvkem. Bublina je jedna
 * na stránku, `position: fixed`, takže ji neořízne tabulka ani karta.
 *
 * Kde události nedorazí (vypnuté tlačítko v některých prohlížečích),
 * zůstane `title` a ukáže se nativní — nic se neztratí.
 */
export function initTooltips() {
    const tip = document.createElement('div');
    tip.className = 'tooltip';
    tip.id = 'app-tooltip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.append(tip);

    let current = null;
    const selector = '[title], [data-tooltip]';

    const text = (el) => {
        if (el.hasAttribute('title')) {
            el.dataset.tooltip = el.getAttribute('title');
            el.removeAttribute('title');
        }

        return (el.dataset.tooltip ?? '').trim();
    };

    const place = (el) => {
        const gap = 8;
        const rect = el.getBoundingClientRect();
        const width = tip.offsetWidth;
        const height = tip.offsetHeight;
        const below = rect.top - height - gap < 8;
        const left = Math.min(Math.max(rect.left + rect.width / 2 - width / 2, 8), window.innerWidth - width - 8);

        tip.style.left = `${left}px`;
        tip.style.top = `${below ? rect.bottom + gap : rect.top - height - gap}px`;
        tip.style.setProperty('--tooltip-arrow', `${rect.left + rect.width / 2 - left}px`);
        tip.classList.toggle('tooltip--below', below);
    };

    const show = (el) => {
        const content = text(el);

        if (content === '') {
            return;
        }

        current = el;
        tip.textContent = content;
        tip.hidden = false;
        el.setAttribute('aria-describedby', tip.id);
        place(el);
    };

    const hide = () => {
        current?.removeAttribute('aria-describedby');
        current = null;
        tip.hidden = true;
    };

    document.addEventListener('pointerover', (event) => {
        const el = event.target instanceof Element ? event.target.closest(selector) : null;

        if (el === current) {
            return;
        }

        hide();

        if (el) {
            show(el);
        }
    });

    document.addEventListener('pointerout', (event) => {
        if (current && !(event.relatedTarget instanceof Node && current.contains(event.relatedTarget))) {
            hide();
        }
    });

    document.addEventListener('focusin', (event) => {
        const el = event.target instanceof Element ? event.target.closest(selector) : null;

        hide();

        if (el) {
            show(el);
        }
    });

    document.addEventListener('focusout', hide);
    document.addEventListener('pointerdown', hide);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hide();
        }
    });
    window.addEventListener('scroll', hide, { passive: true, capture: true });
    window.addEventListener('resize', hide);
}
