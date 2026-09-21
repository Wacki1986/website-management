/**
 * Service worker správy instancí.
 *
 * Leží v kořeni vedle index.php schválně: *scope* service workeru je daný
 * umístěním souboru a musí pokrýt celou správu. `.htaccess` i dev-server
 * ho proto vpouštějí jako jediný skript mimo /assets/.
 *
 * Dělá záměrně málo:
 *
 *  1. Je podmínkou instalace na plochu (PWA) a na iOS podmínkou web pushe.
 *  2. Když je telefon bez signálu, ukáže místo chyby prohlížeče stránku
 *     „Jste bez připojení" s tlačítkem Zkusit znovu.
 *
 * **Správu necachuje.** Stránky nesou CSRF tokeny a čerstvá data;
 * cache nad nimi by vracela cizí nebo starý stav a chyby z ní se hledají
 * špatně. Jediné, co se ukládá, je samotná offline stránka.
 *
 * Push handlery (`push`, `notificationclick`) jsou dole — zprávu skládá
 * PushNotifier na serveru, tady se jen ukáže a po klepnutí otevře.
 */

const VERSION = "v1";
const CACHE = "app-" + VERSION;

// Cesta relativně k tomuto souboru — funguje i v podadresáři (basePath).
const OFFLINE_URL = new URL("assets/offline.html", self.location.href).pathname;

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: "reload" })))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener("fetch", (event) => {
    // Jen navigace (načtení stránky). Assety, fetch z JS ani POSTy se nijak
    // neovlivňují — jdou rovnou na síť jako bez service workeru.
    if (event.request.mode !== "navigate") {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
});

/**
 * Push — zpráva ze serveru (PushNotifier) jako systémové upozornění.
 *
 * Tělo je JSON `{ id, title, body, url }`; `url` vede tam, kde se věc řeší
 * (žádost podpory, přehled instancí). `tag` drží jedno upozornění na věc:
 * novější zpráva k téže žádosti nahradí starou místo druhé bubliny.
 *
 * Upozornění se ukáže **vždy**, i když se tělo nepodaří přečíst: prohlížeč
 * s `userVisibleOnly` trestá tiché pushe odebráním odběru.
 */
self.addEventListener("push", (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {};
    }

    const title = data.title || "Správa instancí";
    const options = {
        body: data.body || "",
        icon: new URL("assets/favicons/icon-192.png", self.location.href).pathname,
        // Badge je ta drobná ikonka ve stavovém řádku telefonu a Android z ní
        // bere POUZE průhlednost — barvy zahodí a zbytek vybarví jednou
        // barvou. Barevná `icon-192.png` (plný čtverec bez alfy) se tak
        // kreslila jako čtvereček. Tohle je bílé „D" na průhledném pozadí.
        badge: new URL("assets/favicons/badge-96.png", self.location.href).pathname,
        tag: data.id ? "n-" + data.id : undefined,
        renotify: Boolean(data.id),
        data: { url: data.url || new URL("./", self.location.href).pathname },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

/**
 * Klepnutí: zaostřit už otevřené okno aplikace a přejít v něm, jinak
 * otevřít nové. Bez toho by každé klepnutí založilo další záložku.
 */
self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || "./", self.location.href).href;

    event.waitUntil(
        self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((windows) => {
            const scope = self.registration.scope;
            const existing = windows.find((w) => w.url.startsWith(scope));
            if (existing) {
                return existing.focus().then((w) => (w && "navigate" in w ? w.navigate(target) : undefined));
            }
            return self.clients.openWindow(target);
        }),
    );
});
