/**
 * Krokovací pole (`.stepper` z návrhu): tlačítka +/− kolem skutečného
 * `<input type="number">`. Bez skriptu jsou tlačítka bez funkce a hodnota
 * se píše do pole rovnou — formulář to nijak nerozbije.
 */
export function initSteppers() {
    document.querySelectorAll('[data-stepper]').forEach((stepper) => {
        const input = stepper.querySelector('input[type="number"]');
        if (!input) {
            return;
        }

        const step = Number(input.step) || 1;
        const clamp = (value) => {
            const min = input.min !== '' ? Number(input.min) : -Infinity;
            const max = input.max !== '' ? Number(input.max) : Infinity;
            return Math.min(max, Math.max(min, value));
        };
        const move = (delta) => {
            input.value = String(clamp((Number(input.value) || 0) + delta));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        stepper.querySelector('[data-stepper-down]')?.addEventListener('click', () => move(-step));
        stepper.querySelector('[data-stepper-up]')?.addEventListener('click', () => move(step));
    });
}
