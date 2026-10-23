/**
 * Toasty. Server je vykreslí rovnou do HTML (fungují tedy i bez JS),
 * tady se jen samy zavírají. `showToast()` přidá hlášku ze skriptu —
 * pro akce na pozadí, po kterých se stránka nenačítá znovu.
 */
export function initToasts() {
    const container = document.querySelector('[data-toasts]');
    if (!container) {
        return;
    }

    container.querySelectorAll('.toast').forEach(autoClose);
}

/** Stejná značka jako toast ze serveru (`layout/shell`). */
export function showToast(message, type = 'error') {
    const container = document.querySelector('[data-toasts]');
    if (!container) {
        return;
    }

    const toast = document.createElement('div');
    const text = document.createElement('span');
    const close = document.createElement('button');

    toast.className = `toast toast--${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    text.textContent = message;
    close.type = 'button';
    close.className = 'toast__close';
    close.dataset.toastClose = '';
    close.setAttribute('aria-label', 'Zavřít hlášku');
    close.textContent = '×';
    toast.append(text, close);
    container.append(toast);
    autoClose(toast);
}

function autoClose(toast) {
    const remove = () => toast.remove();

    toast.querySelector('[data-toast-close]')?.addEventListener('click', remove);
    window.setTimeout(remove, 6000);
}
