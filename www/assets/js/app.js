/**
 * Vstupní bod klientského kódu Správy webů.
 *
 * Stejná zásada jako v klientské aplikaci: skoro bez JavaScriptu, všechno
 * podstatné vykresluje server a bez skriptu to musí fungovat. JS doplňuje
 * jen pohodlí: toasty se samy zavírají, motiv se přepne bez překreslení,
 * heslo jde zobrazit okem.
 */

// Adresy modulů se NEverzují tady (import '…?v=x' by se muselo pamatovat
// ručně a JS má roční immutable cache) — verzované adresy dodává import
// mapa v layoutu (Kernel::jsImportMap), otisk je z času změny souboru.
import { initToasts } from './modules/toast.js';
import { initThemeToggle } from './modules/theme.js';
import { initPasswordToggles } from './modules/password.js';
import { initCopy } from './modules/copy.js';
import { initPopovers } from './modules/popover.js';
import { initServiceWorker } from './modules/pwa.js';
import { initPush } from './modules/push.js';
import { initSelection } from './modules/selection.js';
import { initSteppers } from './modules/stepper.js';
import { initReportPreview } from './modules/report-preview.js';

initToasts();
initThemeToggle();
initPasswordToggles();
initCopy();
initPopovers();
// PWA: registrace service workeru a karta „Toto zařízení" v Nastavení →
// Oznámení. Obojí se tiše přeskočí tam, kde na to prohlížeč nemá.
initServiceWorker();
initPush();
initSelection();
initSteppers();
initReportPreview();
