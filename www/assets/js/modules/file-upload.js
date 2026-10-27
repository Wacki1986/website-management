/**
 * Nahrání souboru jedním klikem — `<input type="file" data-autosubmit>`
 * schovaný v tlačítku (`<label class="btn">`). Po výběru souboru se
 * formulář rovnou odešle; nativní pole „Vybrat soubor / Soubor nevybrán"
 * se neukazuje (v tmavém motivu ho prohlížeč kreslí po svém).
 *
 * Soubor jde i přetáhnout myší: na tlačítko, nebo na nejbližší obal
 * s `data-dropzone` (řádek loga — náhled, popis i tlačítko), aby se
 * nemuselo mířit do malého tlačítka. Při přetahování dostane plocha třídu
 * `is-dragover`. Z víc přetažených souborů se vezme první.
 *
 * Během odesílání dostane formulář `data-uploading` — tlačítka se ztlumí
 * (app/_brand.scss), ať nikdo neklikne podruhé.
 */
export function initFileUpload() {
    document.querySelectorAll('input[type="file"][data-autosubmit]').forEach((input) => {
        input.addEventListener('change', () => submit(input));

        const zone = input.closest('[data-dropzone]') ?? input.closest('label');

        if (zone) {
            initDropzone(zone, input);
        }
    });
}

function submit(input) {
    const form = input.form;

    if (!form || input.files.length === 0 || form.dataset.uploading !== undefined) {
        return;
    }

    form.dataset.uploading = '';
    form.requestSubmit();
}

/** Přetahují se soubory (ne text nebo odkaz ze stránky)? */
function hasFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function initDropzone(zone, input) {
    // dragenter/dragleave chodí i z vnořených prvků — počítadlo drží stav
    // celé plochy, jinak by zvýraznění blikalo nad každým potomkem.
    let depth = 0;

    zone.addEventListener('dragenter', (event) => {
        if (!hasFiles(event)) {
            return;
        }

        event.preventDefault();
        depth++;
        zone.classList.add('is-dragover');
    });

    zone.addEventListener('dragover', (event) => {
        if (!hasFiles(event)) {
            return;
        }

        // Bez preventDefault prohlížeč drop nepovolí a soubor by otevřel.
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });

    zone.addEventListener('dragleave', () => {
        depth = Math.max(0, depth - 1);

        if (depth === 0) {
            zone.classList.remove('is-dragover');
        }
    });

    zone.addEventListener('drop', (event) => {
        if (!hasFiles(event)) {
            return;
        }

        event.preventDefault();
        depth = 0;
        zone.classList.remove('is-dragover');

        const file = event.dataTransfer.files[0];

        if (!file) {
            return;
        }

        // Do pole jde přiřadit jen FileList — vyrobí ho DataTransfer.
        const list = new DataTransfer();
        list.items.add(file);
        input.files = list.files;
        submit(input);
    });
}
