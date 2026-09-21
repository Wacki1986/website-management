/**
 * Přepínač světlý / tmavý motiv v nabídce účtu.
 *
 * Volbu vypisuje server na <html data-theme>, takže motiv nikdy neproblikne.
 * Tady se jen přepne okamžitě a uloží na server (nastavení uživatele, ne cookie —
 * přežije i jiné zařízení).
 *
 * Bez JavaScriptu jsou to obyčejná odesílací tlačítka: formulář odejde POSTem
 * a stránka se překreslí s novým motivem. Tenhle skript to jen zkrátí o jedno
 * načtení — proto `preventDefault` až po tom, co se přepnutí povede zahájit.
 */
export function initThemeToggle() {
    const buttons = document.querySelectorAll('[data-theme-set]');

    if (buttons.length === 0) {
        return;
    }

    // Výchozí volba je „podle systému" a server neví, co systém zrovna hlásí.
    // Bez tohohle by v nabídce nesvítilo nic a přepínač by vypadal nenastavený.
    if (!document.documentElement.dataset.theme || document.documentElement.dataset.theme === 'auto') {
        const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        buttons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.themeSet === (dark ? 'dark' : 'light'));
        });
    }

    buttons.forEach((button) => {
        button.addEventListener('click', (event) => {
            const next = button.dataset.themeSet;

            event.preventDefault();
            document.documentElement.dataset.theme = next;

            // Zvýraznění zvolené možnosti — server ho po překreslení pošle sám,
            // ale k tomu tady nedojde.
            buttons.forEach((other) => {
                other.classList.toggle('is-active', other === button);
            });

            fetch(button.form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ theme: next }),
            }).catch(() => {
                // Když se uložení nepovede, motiv zůstane přepnutý aspoň pro tuto relaci.
            });
        });
    });
}
