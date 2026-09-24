/**
 * Trezor přístupů (Detail webu → Přístupy): oko a kopírování hesla.
 *
 * Heslo ve stránce není — tlačítka nesou adresu (`data-secret-reveal`,
 * `data-secret-copy`), odkud si ho POSTem s CSRF tokenem dotáhnou.
 * `data-secret-format="filezilla"` místo hesla vrátí adresu pro rychlé
 * připojení FileZilly (`sftp://jmeno:heslo@server:port`).
 *
 * Ukázané heslo po půl minutě samo zmizí — aby nezůstalo na obrazovce,
 * když se od počítače odejde.
 */
const HIDE_AFTER_MS = 30000;
const MASK = '••••••••';

export function initVault() {
    document.querySelectorAll('[data-secret-reveal]').forEach((button) => {
        let timer = 0;
        const cell = button.closest('[data-secret]');
        const value = cell?.querySelector('[data-secret-value]');

        if (!value) {
            return;
        }

        const hide = () => {
            window.clearTimeout(timer);
            value.textContent = MASK;
            value.classList.remove('is-revealed');
            setPressed(button, false);
        };

        button.addEventListener('click', async () => {
            if (button.getAttribute('aria-pressed') === 'true') {
                hide();
                return;
            }

            try {
                value.textContent = await fetchSecret(button.dataset.secretReveal);
                value.classList.add('is-revealed');
                setPressed(button, true);
                timer = window.setTimeout(hide, HIDE_AFTER_MS);
            } catch (error) {
                fail(button, error.message);
            }
        });
    });

    document.querySelectorAll('[data-secret-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await copy(fetchSecret(button.dataset.secretCopy, button.dataset.secretFormat ?? ''));
                flash(button, 'is-done');
            } catch (error) {
                fail(button, error.message);
            }
        });
    });
}

/**
 * Do schránky text, který teprve přijde ze serveru.
 *
 * Safari pustí zápis do schránky jen v rámci kliknutí; než dorazí odpověď
 * serveru, kliknutí „vyprší". `ClipboardItem` s příslibem to obchází —
 * zápis začne hned, obsah se doplní, až přijde. Kde to prohlížeč neumí,
 * počká se na text a zapíše obyčejně.
 */
async function copy(textPromise) {
    if (window.ClipboardItem && navigator.clipboard?.write) {
        try {
            const blob = textPromise.then((text) => new Blob([text], { type: 'text/plain' }));
            await navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
            return;
        } catch (error) {
            // Chyba serveru se hlásí rovnou; jen odmítnutý zápis zkusí druhou cestu.
            await textPromise;
        }
    }

    await navigator.clipboard.writeText(await textPromise);
}

async function fetchSecret(url, format = '') {
    const body = new FormData();
    body.set('format', format);

    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.ok !== true) {
        throw new Error(data.message || `Heslo se nepodařilo načíst (chyba ${response.status}).`);
    }

    return String(data.value ?? '');
}

function setPressed(button, pressed) {
    const label = pressed ? 'Skrýt heslo' : 'Ukázat heslo';

    button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    // Bublinu kreslí tooltip.js z `data-tooltip` (`title` si při najetí převezme).
    button.removeAttribute('title');
    button.dataset.tooltip = label;

    const icon = button.querySelector('.icon');
    if (icon) {
        const mask = `var(--icon-${pressed ? 'eye-off' : 'eye'}) center/contain no-repeat`;
        icon.style.webkitMask = mask;
        icon.style.mask = mask;
    }
}

/** Krátké potvrzení na tlačítku (fajfka po zkopírování). */
function flash(button, className) {
    button.classList.add(className);
    window.setTimeout(() => button.classList.remove(className), 1600);
}

/**
 * Chyba (vypršelé přihlášení, vyměněný app_key): červený rámeček na
 * tlačítku a text chyby na chvíli místo teček v řádku.
 */
function fail(button, message) {
    const value = button.closest('.table__row')?.querySelector('[data-secret-value]');

    button.classList.add('is-failed');

    if (value) {
        value.textContent = message;
        value.classList.add('credential-value__error');
    }

    window.setTimeout(() => {
        button.classList.remove('is-failed');

        if (value) {
            value.textContent = MASK;
            value.classList.remove('credential-value__error');
        }
    }, 6000);
}
