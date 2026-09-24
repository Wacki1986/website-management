/**
 * QR kód z textu v `data-qr` — párování dvoufázového přihlášení
 * (Nastavení → Dvoufázové přihlášení).
 *
 * Knihovna (vendor/qrcode.js, Kazuhiko Arase, MIT) má 50 kB, takže se
 * načítá jen na stránce, kde QR kód je. Kreslí se jako SVG s bílým
 * podkladem a okrajem: aplikace v telefonu přečte jen tmavý kód na
 * světlém, i když je správa v tmavém režimu.
 */
export async function initQrCodes() {
    const targets = document.querySelectorAll('[data-qr]');

    if (targets.length === 0) {
        return;
    }

    const { default: qrcode } = await import('../vendor/qrcode.js');

    targets.forEach((target) => {
        // Typ 0 = nejmenší velikost, do které se text vejde; M = střední oprava chyb.
        const qr = qrcode(0, 'M');
        qr.addData(target.dataset.qr);
        qr.make();

        target.replaceChildren(toSvg(qr));
    });
}

function toSvg(qr) {
    const count = qr.getModuleCount();
    const margin = 4; // „tichá zóna" ze specifikace QR — bez ní čtečky tápou
    const size = count + margin * 2;
    const ns = 'http://www.w3.org/2000/svg';
    let path = '';

    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (qr.isDark(row, col)) {
                path += `M${col + margin} ${row + margin}h1v1h-1z`;
            }
        }
    }

    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
    svg.setAttribute('shape-rendering', 'crispEdges');
    svg.setAttribute('aria-hidden', 'true');

    const background = document.createElementNS(ns, 'rect');
    background.setAttribute('width', String(size));
    background.setAttribute('height', String(size));
    background.setAttribute('fill', '#fff');

    const modules = document.createElementNS(ns, 'path');
    modules.setAttribute('d', path);
    modules.setAttribute('fill', '#000');

    svg.append(background, modules);

    return svg;
}
