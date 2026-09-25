/**
 * Vstupní bod klientského kódu Správy webů.
 *
 * Každý modul se načte a spustí zvlášť: když jeden selže (chybí na
 * serveru, chyba v kódu), ostatní běží dál a do konzole se zapíše, který
 * to byl. Se statickými importy by jeden vadný modul potichu shodil
 * všechny — aktualizace pluginů, okna, bubliny.
 */

// Adresy modulů se NEverzují tady (import '…?v=x' by se muselo pamatovat
// ručně a JS má roční immutable cache) — verzované adresy dodává import
// mapa v layoutu (Kernel::jsImportMap), otisk je z času změny souboru.
// Pořadí spuštění je pořadí v seznamu.
const modules = [
    ['./modules/toast.js', 'initToasts'],
    ['./modules/theme.js', 'initThemeToggle'],
    ['./modules/password.js', 'initPasswordToggles'],
    ['./modules/copy.js', 'initCopy'],
    ['./modules/popover.js', 'initPopovers'],
    // PWA: registrace service workeru a karta „Toto zařízení" v Nastavení →
    // Oznámení. Obojí se tiše přeskočí tam, kde na to prohlížeč nemá.
    ['./modules/pwa.js', 'initServiceWorker'],
    ['./modules/push.js', 'initPush'],
    ['./modules/selection.js', 'initSelection'],
    ['./modules/stepper.js', 'initSteppers'],
    ['./modules/report-preview.js', 'initReportPreview'],
    ['./modules/pending.js', 'initPending'],
    ['./modules/plugin-update.js', 'initPluginUpdate'],
    ['./modules/library-update.js', 'initLibraryUpdate'],
    ['./modules/confirm-dialog.js', 'initConfirmDialogs'],
    ['./modules/service-tasks.js', 'initServiceTasks'],
    ['./modules/report-template.js', 'initReportTemplate'],
    ['./modules/tooltip.js', 'initTooltips'],
    ['./modules/vault.js', 'initVault'],
    ['./modules/qr.js', 'initQrCodes'],
];

const loaded = await Promise.allSettled(modules.map(([path]) => import(path)));

loaded.forEach((result, index) => {
    const [path, init] = modules[index];

    try {
        if (result.status === 'rejected') {
            throw result.reason;
        }

        result.value[init]();
    } catch (error) {
        console.error(`[Správa webů] ${path} (${init}) selhal:`, error);
    }
});
