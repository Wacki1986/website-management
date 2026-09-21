/**
 * Registrace service workeru (PWA).
 *
 * Adresu nese `<meta name="app-sw">` z partials/head-icons.php — skript tak
 * nemusí znát basePath instalace. Registrace je tichá: když prohlížeč service
 * workery neumí (nebo běžíme přes http bez certifikátu), nic se nestane
 * a správa funguje jako dřív. Sám worker dělá offline stránku a upozornění,
 * viz /sw.js.
 */
export function initServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const meta = document.querySelector('meta[name="app-sw"]');
    if (!meta || !meta.content) {
        return;
    }

    // Až po načtení stránky — registrace nemá soupeřit o síť s obsahem.
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(meta.content).catch(() => {
            // Bez service workeru se obejdeme; chyba by uživateli nic neřekla.
            // Že se nespustil, pozná karta v Nastavení → Oznámení (push.js).
        });
    });
}
