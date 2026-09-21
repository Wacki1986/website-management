/**
 * Toasty. Server je vykreslí rovnou do HTML (fungují tedy i bez JS),
 * tady se jen samy zavírají.
 */
export function initToasts() {
    const container = document.querySelector('[data-toasts]');
    if (!container) {
        return;
    }

    container.querySelectorAll('.toast').forEach((toast) => {
        const remove = () => toast.remove();

        toast.querySelector('[data-toast-close]')?.addEventListener('click', remove);
        window.setTimeout(remove, 6000);
    });
}
