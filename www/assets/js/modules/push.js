/**
 * Upozornění na telefon — přihlášení tohoto prohlížeče k web pushi.
 *
 * Obsluhuje kartu v Nastavení → Oznámení (`[data-push]`): zjistí, jestli
 * prohlížeč push umí a jestli už je odběr zapsaný, a přepíná ho tlačítky.
 * Server (`POST /nastaveni/oznameni/prihlasit`) dostane to, co vydá
 * `pushManager.subscribe()`.
 *
 * Dvě věci, které nejsou vidět z kódu:
 *
 *  - Oprávnění se žádá **jen z kliknutí** — iOS žádost mimo uživatelské
 *    gesto tiše zahodí a Chrome ji zablokuje jako otravnou.
 *  - Na iPhonu `PushManager` existuje jen v aplikaci spuštěné z plochy.
 *    V Safari v záložce chybí, takže se ukáže „prohlížeč to neumí" —
 *    a nápověda nad tím říká, co udělat.
 */
export function initPush() {
    const root = document.querySelector('[data-push]');
    if (!root) {
        return;
    }

    const unsupported = document.querySelector('[data-push-unsupported]');

    if (!pushSupported()) {
        if (unsupported) {
            unsupported.hidden = false;
        }
        return;
    }

    root.hidden = false;

    const status = root.querySelector('[data-push-status]');
    const onButton = root.querySelector('[data-push-on]');
    const offButton = root.querySelector('[data-push-off]');

    const show = (text, subscribed) => {
        if (status) {
            status.textContent = text;
        }
        onButton.hidden = subscribed;
        offButton.hidden = !subscribed;
    };

    const busy = (flag) => {
        onButton.disabled = flag;
        offButton.disabled = flag;
    };

    const markThisDevice = async (subscription) => {
        if (!subscription || !window.crypto?.subtle) {
            return;
        }
        // Server zná jen SHA-256 endpointu; spočítáme ho tu, ať jde v seznamu
        // zvýraznit řádek tohoto zařízení.
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(subscription.endpoint));
        const hash = Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
        document.querySelectorAll('[data-push-device]').forEach((row) => {
            const isThisDevice = row.dataset.pushDevice === hash;
            const tag = row.querySelector('.push-devices__this');
            if (tag) {
                tag.hidden = !isThisDevice;
            }
            if (isThisDevice) {
                wireOwnRemoval(row);
            }
        });
    };

    /**
     * „Odebrat" u řádku **tohoto** zařízení: smazání jen na serveru by nic
     * nezměnilo — kontrola v refresh() odběr, který v prohlížeči zůstal,
     * při obnovení stránky poctivě nahlásí znovu a řádek by obživl (nález
     * z provozu klientské aplikace). Proto se odběr nejdřív zruší
     * v prohlížeči a teprve pak odejde formulář; stránka se načte už bez
     * něj. Řádků cizích zařízení se to netýká — jejich odběr odsud zrušit
     * nejde.
     */
    const wireOwnRemoval = (row) => {
        const form = row.querySelector('form.push-devices__remove');
        if (!form || form.dataset.pushWired) {
            return;
        }
        form.dataset.pushWired = '1';
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            readyWorker()
                .then((registration) => registration.pushManager.getSubscription())
                .then((subscription) => subscription?.unsubscribe())
                .catch(() => { /* nevyšlo — server smaže aspoň svůj záznam */ })
                // form.submit() posluchače obchází, takže se smyčka nechytne.
                .finally(() => form.submit());
        });
    };

    const refresh = async () => {
        const registration = await readyWorker();
        const subscription = await registration.pushManager.getSubscription();

        // Zákaz mohl mezitím padnout (viz visibilitychange) — tlačítko se
        // musí umět i odemknout, nejen zamknout.
        onButton.disabled = false;

        if (Notification.permission === 'denied') {
            show(permissionHelp(), false);
            onButton.disabled = true;
            return;
        }

        if (!subscription) {
            show('Upozornění na tomto zařízení jsou vypnutá.', false);
            return;
        }

        /**
         * Odběr v prohlížeči ≠ odběr na serveru. Když při zapínání POST
         * nedoběhl (výpadek, odepřené oprávnění na první pokus), prohlížeč
         * odběr má, server ne — a karta by tvrdila „zapnuto", zatímco
         * v seznamu zařízení nic není (nález z provozu klientské aplikace).
         * Proto se existující odběr při každém načtení pošle znovu; uložení
         * je idempotentní.
         */
        const response = await postJson(root.dataset.pushSubscribe, subscription.toJSON());
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            await subscription.unsubscribe();
            show('Odběr se nepodařilo uložit' + (data.message ? ': ' + data.message : '.') + ' Zkuste ho zapnout znovu.', false);
            return;
        }

        show('Upozornění na tomto zařízení jsou zapnutá.', true);
        markThisDevice(subscription);
    };

    onButton.addEventListener('click', async () => {
        busy(true);
        try {
            const result = await enablePush(root.dataset.pushKey, root.dataset.pushSubscribe);
            if (!result.ok) {
                show(result.message, false);
                return;
            }

            show('Upozornění na tomto zařízení jsou zapnutá. Seznam zařízení se doplní po obnovení stránky.', true);
            markThisDevice(result.subscription);
        } catch (error) {
            show('Zapnutí se nepovedlo: ' + (error?.message || 'neznámá chyba'), false);
        } finally {
            busy(false);
        }
    });

    offButton.addEventListener('click', async () => {
        busy(true);
        try {
            const registration = await readyWorker();
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await postJson(root.dataset.pushUnsubscribe, { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            show('Upozornění na tomto zařízení jsou vypnutá.', false);
        } catch (error) {
            show('Vypnutí se nepovedlo: ' + (error?.message || 'neznámá chyba'), true);
        } finally {
            busy(false);
        }
    });

    const check = () => refresh().catch(
        (error) => show('Stav upozornění se nepodařilo zjistit: ' + (error?.message || 'neznámá chyba'), false),
    );

    /**
     * Návrat do aplikace stav přepočítá.
     *
     * Kdo si šel oznámení povolit do nastavení telefonu, vrátí se na stránku,
     * která pořád tvrdí „zakázáno" a má zašedlé tlačítko — a zkouší to znovu
     * a znovu, protože ho nenapadne stránku načíst. Bez tohohle řádku to vypadá
     * jako porucha aplikace.
     */
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            check();
        }
    });

    check();
}

/**
 * Proč se povolení nepovedlo — a hlavně kde ho hledat.
 *
 * Na telefonu za tím skoro vždycky stojí **systémové nastavení aplikace**,
 * ne prohlížeč: Android 13 a novější má vlastní vypínač oznámení u každé
 * aplikace a nainstalovaná PWA je aplikace jako každá jiná. Když je vypnutý,
 * skončí `requestPermission()` jako `denied` **bez jakéhokoli dialogu** —
 * takže to zvenčí vypadá, že tlačítko nefunguje.
 */
function permissionHelp() {
    const installed = window.matchMedia('(display-mode: standalone)').matches;

    return installed
        ? 'Oznámení má zakázaná sám telefon. Zapněte je v systémovém nastavení u téhle aplikace a zkuste to znovu — stránka se přepne sama.'
        : 'Oznámení jsou pro tuhle stránku zakázaná. Povolte je v nastavení webu v prohlížeči; na telefonu je navíc musí mít povolená i aplikace v nastavení systému.';
}

/** Umí tenhle prohlížeč web push? (Na iPhonu jen v aplikaci z plochy.) */
function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * `navigator.serviceWorker.ready` s časovým stropem.
 *
 * Samotný příslib se **nikdy** nevyresolvuje, dokud se nějaký worker
 * nezaregistruje — a když ho `.htaccess` nepustí ven nebo chybí
 * `assets/offline.html` (bez něj instalace workeru selže), zůstane karta
 * navěky viset na „Zjišťuji stav…" a není poznat proč. Pět vteřin a jasná
 * věta ušetří hodinu hledání.
 */
function readyWorker() {
    const limit = new Promise((resolve, reject) => {
        setTimeout(() => reject(new Error('service worker se nespustil — ověřte, že /sw.js vrací skript a že existuje /assets/offline.html')), 5000);
    });

    return Promise.race([navigator.serviceWorker.ready, limit]);
}

/**
 * Zapne odběr pushe v tomhle prohlížeči a ohlásí ho serveru.
 *
 * Vrací `{ ok, message?, subscription? }` — `message` je hotová věta
 * k zobrazení. Chyby prohlížeče (odmítnutý `subscribe()` apod.) nechává
 * probublat, ať si je volající ohlásí po svém.
 */
async function enablePush(key, subscribeUrl) {
    const permission = await Notification.requestPermission();

    // Rozdíl je podstatný: `denied` znamená „někdo to zakázal" (často telefon,
    // viz permissionHelp), `default` jen „zavřel jsem dialog".
    if (permission === 'denied') {
        return { ok: false, message: permissionHelp() };
    }

    if (permission !== 'granted') {
        return { ok: false, message: 'Povolení nebylo potvrzené — bez něj upozornění zapnout nejde.' };
    }

    const registration = await readyWorker();
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(key),
    });

    const response = await postJson(subscribeUrl, subscription.toJSON());
    if (!response.ok) {
        // Server odběr nevzal — ať v prohlížeči neleží sirotek.
        await subscription.unsubscribe();
        const data = await response.json().catch(() => ({}));
        return { ok: false, message: data.message || 'Uložení se nepovedlo, zkuste to znovu.' };
    }

    return { ok: true, subscription };
}

/** POST s JSON tělem a CSRF tokenem z hlavičky stránky. */
function postJson(url, body) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
}

/** VAPID veřejný klíč je base64url; `subscribe()` chce bajty. */
function base64UrlToBytes(text) {
    const padded = (text + '='.repeat((4 - (text.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }
    return bytes;
}
