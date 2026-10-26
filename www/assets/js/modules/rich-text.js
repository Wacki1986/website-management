import { showToast } from './toast.js';

/**
 * Malý editor poznámek: tučně, odkaz, odrážky a číslovaný seznam.
 *
 * Pole `<textarea data-rich-text>` zůstane ve formuláři (skryté) a drží
 * text se značkami — editor ho přepíše po každé změně, odesílá se tedy
 * obyčejný text:
 *
 *   **tučně**              [popis](https://adresa)   https://adresa
 *   - odrážka              1. číslovaný bod
 *
 * Ukládá se text, ne HTML: poznámka je čitelná i bez editoru a vložený
 * text z Wordu nebo webu s sebou nenese cizí formátování. Editor kreslí
 * jen prvky, které si sám vytvoří (`createElement`, `textContent`).
 *
 * Zkratky: Ctrl+B tučně, Ctrl+K odkaz; Ctrl+klik na odkaz ho otevře.
 */
export function initRichText() {
    document.querySelectorAll('textarea[data-rich-text]').forEach(setup);
}

/** Adresa odkazu — jen web a e-mail, nic jiného se do odkazu nedostane. */
const SAFE_URL = /^(https?:\/\/[^\s]+|mailto:[^\s@]+@[^\s]+)$/i;

/** Značky v řádku: [popis](adresa), **tučně**, holá adresa. */
const INLINE = /\[([^\]\n]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|(https?:\/\/[^\s<]*[^\s<.,;:!?)\]'"])/g;

/** Prvky, které v editoru tvoří samostatný řádek (prohlížeč je vkládá při Enteru). */
const BLOCKS = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'PRE', 'UL', 'OL', 'LI']);

function setup(textarea) {
    const wrapper = document.createElement('div');
    const toolbar = document.createElement('div');
    const area = document.createElement('div');

    wrapper.className = 'rich-text';
    // Odsazení, které šablona dala poli (poznámka u reportu), patří editoru.
    wrapper.style.marginTop = textarea.style.marginTop;
    toolbar.className = 'rich-text__toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Formátování');

    area.className = 'rich-text__area';
    area.contentEditable = 'true';
    area.setAttribute('role', 'textbox');
    area.setAttribute('aria-multiline', 'true');
    area.setAttribute('aria-label', textarea.getAttribute('aria-label') || textarea.labels?.[0]?.textContent.trim() || 'Poznámka');
    area.dataset.placeholder = textarea.placeholder || '';
    area.style.setProperty('--rich-text-rows', String(textarea.rows || 6));

    const buttons = {
        bold: button('Tučně (Ctrl+B)', 'bold', () => command('bold')),
        link: button('Odkaz (Ctrl+K)', 'link', () => editLink(area, sync)),
        list: button('Odrážky', 'list', () => command('insertUnorderedList')),
        ordered: button('Číslovaný seznam', 'list-ordered', () => command('insertOrderedList')),
    };

    toolbar.append(...Object.values(buttons));
    wrapper.append(toolbar, area);
    textarea.before(wrapper);
    textarea.hidden = true;

    area.append(toFragment(textarea.value));
    markEmpty();

    // Pole jen pro čtení (odeslaný report) — text vidět, upravit nejde.
    if (textarea.disabled || textarea.readOnly) {
        area.contentEditable = 'false';
        wrapper.classList.add('rich-text--readonly');
        Object.values(buttons).forEach((element) => { element.disabled = true; });

        return;
    }

    let syncing = false;

    function command(name) {
        area.focus();
        document.execCommand(name, false);
        sync();
    }

    // Změnu ohlásí i na poli, ať ji vidí jiné skripty (počítadlo znaků u reportu).
    function sync() {
        textarea.value = toText(area);
        markEmpty();
        updateButtons();
        syncing = true;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        syncing = false;
    }

    // Text do pole vložil jiný skript (fráze u poznámky reportu) — překreslit.
    textarea.addEventListener('input', () => {
        if (!syncing) {
            area.replaceChildren(toFragment(textarea.value));
            markEmpty();
        }
    });

    // Kdo pole „zaměří" (fráze u reportu, klik na popisek), zaměří editor.
    textarea.focus = () => area.focus();
    [...(textarea.labels ?? [])].forEach((label) => label.addEventListener('click', (event) => {
        event.preventDefault();
        area.focus();
    }));

    function markEmpty() {
        area.classList.toggle('rich-text__area--empty', area.textContent.trim() === '' && !area.querySelector('li'));
    }

    // Stav tlačítek podle místa kurzoru (je tu tučné písmo, jsem v seznamu?).
    function updateButtons() {
        if (!area.contains(document.getSelection()?.anchorNode ?? null)) {
            return;
        }

        buttons.bold.setAttribute('aria-pressed', String(document.queryCommandState('bold')));
        buttons.list.setAttribute('aria-pressed', String(document.queryCommandState('insertUnorderedList')));
        buttons.ordered.setAttribute('aria-pressed', String(document.queryCommandState('insertOrderedList')));
        buttons.link.setAttribute('aria-pressed', String(closestLink(area) !== null));
    }

    area.addEventListener('focus', () => {
        // Enter má zakládat odstavce <p> (ne <div>) a tučné písmo značku <b>, ne styl.
        document.execCommand('defaultParagraphSeparator', false, 'p');
        document.execCommand('styleWithCSS', false, false);
    });

    area.addEventListener('input', sync);
    document.addEventListener('selectionchange', updateButtons);

    // Vložený text jen jako text — formátování z Wordu nebo webu by se stejně neuložilo.
    area.addEventListener('paste', (event) => {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
    });

    area.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) {
            return;
        }

        if (event.key.toLowerCase() === 'k') {
            event.preventDefault();
            editLink(area, sync);
        } else if (event.key.toLowerCase() === 'b') {
            event.preventDefault();
            command('bold');
        }
    });

    // V editovatelném poli prohlížeč odkaz neotevře — Ctrl+klik ano.
    area.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (link && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            window.open(link.href, '_blank', 'noopener');
        }
    });

    // Před odesláním ještě jednou, kdyby poslední změna neprošla událostí `input`.
    textarea.form?.addEventListener('submit', () => {
        textarea.value = toText(area);
    });
}

function button(label, icon, action) {
    const element = document.createElement('button');
    const glyph = document.createElement('span');

    element.type = 'button';
    element.className = 'btn btn--ghost btn--icon rich-text__button';
    element.title = label;
    element.setAttribute('aria-label', label);
    element.setAttribute('aria-pressed', 'false');
    glyph.className = `icon icon--sm rich-text__icon rich-text__icon--${icon}`;
    glyph.setAttribute('aria-hidden', 'true');
    element.append(glyph);

    // Klik na tlačítko nesmí vzít editoru výběr textu.
    element.addEventListener('mousedown', (event) => event.preventDefault());
    element.addEventListener('click', action);

    return element;
}

// --- odkaz ------------------------------------------------------------------

let linkDialog = null;

/** Okno „Odkaz" — jedno pro všechny editory na stránce. */
function dialog() {
    if (linkDialog) {
        return linkDialog;
    }

    const template = document.createElement('template');

    template.innerHTML = `
        <dialog class="modal" aria-labelledby="rich-text-link-title">
            <form class="modal__body" method="dialog">
                <div>
                    <div class="card__title modal__title" id="rich-text-link-title">Odkaz</div>
                    <div class="card__note">Adresa webu nebo e-mail. Prázdné pole odkaz zruší.</div>
                </div>
                <input class="form__control" type="text" name="url" placeholder="https://" autocomplete="off" spellcheck="false" aria-label="Adresa odkazu">
                <div class="modal__actions">
                    <button type="button" class="btn btn--ghost" data-link-cancel>Zrušit</button>
                    <button type="submit" class="btn btn--primary" value="ok">Uložit odkaz</button>
                </div>
            </form>
        </dialog>`;

    linkDialog = template.content.firstElementChild;
    document.body.append(linkDialog);

    linkDialog.querySelector('[data-link-cancel]').addEventListener('click', () => linkDialog.close(''));
    linkDialog.addEventListener('click', (event) => {
        if (event.target === linkDialog) {
            linkDialog.close('');
        }
    });

    return linkDialog;
}

function editLink(area, sync) {
    const selection = document.getSelection();

    if (!selection || selection.rangeCount === 0 || !area.contains(selection.anchorNode)) {
        area.focus();
        return;
    }

    const range = selection.getRangeAt(0).cloneRange();
    const existing = closestLink(area);
    const modal = dialog();
    const input = modal.querySelector('input');

    input.value = existing ? existing.getAttribute('href').replace(/^mailto:/i, '') : '';
    modal.returnValue = '';
    modal.showModal();
    input.select();

    modal.addEventListener('close', () => {
        area.focus();
        selection.removeAllRanges();
        selection.addRange(range);

        if (modal.returnValue === 'ok') {
            applyLink(input.value, existing, range);
            sync();
        }
    }, { once: true });
}

function applyLink(value, existing, range) {
    const url = normalizeUrl(value);

    if (url === '') {
        // Prázdná adresa = zrušit odkaz, text zůstane.
        if (existing) {
            existing.replaceWith(...existing.childNodes);
        }

        return;
    }

    if (url === null) {
        showToast('Odkaz musí vést na web (https://…) nebo na e-mail.');
        return;
    }

    if (existing) {
        existing.setAttribute('href', url);
    } else if (range.collapsed) {
        // Bez označeného textu vloží odkaz s adresou jako textem.
        const link = document.createElement('a');

        link.href = url;
        link.textContent = url.replace(/^mailto:/i, '');
        range.insertNode(link);
        range.setStartAfter(link);
        range.collapse(true);
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    } else {
        document.execCommand('createLink', false, url);
    }
}

/** „neurin.cz" → https://neurin.cz, „jan@firma.cz" → mailto:; '' = zrušit, null = nepovolená adresa. */
function normalizeUrl(value) {
    let url = value.trim();

    if (url === '') {
        return '';
    }

    if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(url)) {
        url = `mailto:${url}`;
    } else if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) {
        url = `https://${url}`;
    }

    return SAFE_URL.test(url) ? url : null;
}

function closestLink(area) {
    const node = document.getSelection()?.anchorNode ?? null;
    const element = node?.nodeType === Node.TEXT_NODE ? node.parentElement : node;
    const link = element?.closest?.('a') ?? null;

    return link && area.contains(link) ? link : null;
}

// --- text se značkami → editor -----------------------------------------------

/** Řádky textu → odstavce a seznamy. Prázdný řádek = prázdný odstavec. */
function toFragment(text) {
    const fragment = document.createDocumentFragment();
    let list = null;

    for (const line of text.replace(/\r\n?/g, '\n').split('\n')) {
        const bullet = line.match(/^\s*[-•]\s+(.*)$/) ?? line.match(/^\s*\*\s+(.*)$/);
        const number = bullet ? null : line.match(/^\s*\d+[.)]\s+(.*)$/);
        const item = bullet ?? number;

        if (item) {
            const tag = bullet ? 'UL' : 'OL';

            if (!list || list.tagName !== tag) {
                list = document.createElement(tag);
                fragment.append(list);
            }

            const li = document.createElement('li');
            appendInline(li, item[1]);
            list.append(li.childNodes.length ? li : withBreak(li));
            continue;
        }

        list = null;
        const paragraph = document.createElement('p');
        appendInline(paragraph, line);
        fragment.append(paragraph.childNodes.length ? paragraph : withBreak(paragraph));
    }

    return fragment;
}

function withBreak(element) {
    element.append(document.createElement('br'));

    return element;
}

function appendInline(parent, text) {
    let last = 0;

    for (const match of text.matchAll(INLINE)) {
        const [whole, label, href, bold, bare] = match;

        if (match.index > last) {
            parent.append(text.slice(last, match.index));
        }

        if (label !== undefined && SAFE_URL.test(href)) {
            const link = document.createElement('a');
            link.href = href;
            link.textContent = label;
            parent.append(link);
        } else if (bold !== undefined) {
            const strong = document.createElement('b');
            appendInline(strong, bold);
            parent.append(strong);
        } else if (bare !== undefined) {
            const link = document.createElement('a');
            link.href = bare;
            link.textContent = bare;
            parent.append(link);
        } else {
            parent.append(whole);
        }

        last = match.index + whole.length;
    }

    if (last < text.length) {
        parent.append(text.slice(last));
    }
}

// --- editor → text se značkami -----------------------------------------------

function toText(area) {
    const lines = [];

    collect(area, lines);

    while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
        lines.pop();
    }

    return lines.join('\n');
}

/**
 * Projde potomky prvku: bloky dávají vlastní řádky, souvislý běh textu
 * a značek mezi nimi tvoří řádek(y) taky (Firefox nechává text i přímo
 * v editoru, bez odstavce).
 */
function collect(element, lines) {
    let run = '';
    const flush = () => {
        // Samé mezery mezi bloky nejsou řádek; samotné <br> ano (prázdný řádek).
        if (run.trim() !== '' || run.includes('\n')) {
            lines.push(...trimBreak(run).split('\n'));
        }

        run = '';
    };

    for (const node of element.childNodes) {
        if (node.nodeType !== Node.ELEMENT_NODE || !BLOCKS.has(node.tagName)) {
            run += inline(node, false);
            continue;
        }

        flush();

        if (node.tagName === 'UL' || node.tagName === 'OL') {
            listLines(node, lines);
        } else if ([...node.children].some((child) => BLOCKS.has(child.tagName))) {
            collect(node, lines);
        } else {
            lines.push(...trimBreak(inlineChildren(node, false)).split('\n'));
        }
    }

    flush();
}

/** Položky seznamu; vnořený seznam (Tab) se zarovná pod rodiče. */
function listLines(list, lines) {
    let number = 1;

    for (const item of list.children) {
        if (item.tagName !== 'LI') {
            continue;
        }

        let text = '';
        const nested = [];

        for (const child of item.childNodes) {
            if (child.nodeType === Node.ELEMENT_NODE && (child.tagName === 'UL' || child.tagName === 'OL')) {
                nested.push(child);
            } else {
                text += inline(child, false);
            }
        }

        lines.push((list.tagName === 'OL' ? `${number++}. ` : '- ') + trimBreak(text).replace(/\n+/g, ' ').trim());
        nested.forEach((child) => listLines(child, lines));
    }
}

/** Koncové <br> je jen výplň (prázdný odstavec, konec řádku), ne řádek navíc. */
function trimBreak(text) {
    return text.endsWith('\n') ? text.slice(0, -1) : text;
}

function inlineChildren(node, inBold) {
    return [...node.childNodes].map((child) => inline(child, inBold)).join('');
}

function inline(node, inBold) {
    if (node.nodeType === Node.TEXT_NODE) {
        return node.data.replace(/ /g, ' ');
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
    }

    if (node.tagName === 'BR') {
        return '\n';
    }

    const isLink = node.tagName === 'A';
    const bold = !inBold && !isLink && (node.tagName === 'B' || node.tagName === 'STRONG' || Number(node.style?.fontWeight) >= 600 || node.style?.fontWeight === 'bold');
    // Popis odkazu je prostý text — značka tučně by se do něj zapsala doslova.
    const inner = inlineChildren(node, inBold || bold || isLink) + (BLOCKS.has(node.tagName) ? '\n' : '');

    if (isLink) {
        const href = node.getAttribute('href') ?? '';
        const label = inner.replace(/\n/g, ' ').replace(/\[/g, '(').replace(/\]/g, ')');

        if (!SAFE_URL.test(href) || label.trim() === '') {
            return inner;
        }

        // Odkaz s adresou jako textem zůstane holou adresou — čitelnější bez editoru.
        return label === href && /^https?:/i.test(href) ? href : `[${label}](${href.replace(/\)/g, '%29')})`;
    }

    if (bold) {
        // **…** po řádcích a bez mezer uvnitř, jinak by značka nefungovala.
        return inner.split('\n').map((part) => {
            const [, before, core, after] = part.match(/^(\s*)([\s\S]*?)(\s*)$/);

            return core === '' ? part : `${before}**${core}**${after}`;
        }).join('\n');
    }

    return inner;
}
