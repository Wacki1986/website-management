/**
 * Přepínač viditelnosti u polí s heslem a tokenem.
 *
 * Tlačítko přidává JavaScript, ne šablona — bez skriptu zůstane obyčejné pole
 * s hvězdičkami, které funguje úplně stejně. Kdyby bylo v HTML natrvalo,
 * viselo by tam i tam, kde ho není čím obsloužit.
 *
 * Přepíná se `type` mezi `password` a `text`. Prohlížeč si u textového pole
 * pamatuje hodnotu i pozici kurzoru, takže se po přepnutí dá psát dál.
 */
export function initPasswordToggles() {
    document.querySelectorAll('input[type="password"]').forEach(attachToggle);
}

function attachToggle(input) {
    // Dvojí obalení by přidalo dvě tlačítka — třeba po opětovné inicializaci.
    if (input.closest('.password-field')) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-field__toggle';
    setState(button, false);

    button.addEventListener('click', () => {
        const shown = input.type === 'text';

        input.type = shown ? 'password' : 'text';
        setState(button, !shown);

        // Kurzor zůstane tam, kde byl — jinak skočí na začátek.
        const end = input.value.length;
        input.focus();
        input.setSelectionRange(end, end);
    });

    wrapper.appendChild(button);
}

function setState(button, shown) {
    button.dataset.shown = shown ? 'true' : 'false';
    button.setAttribute('aria-pressed', shown ? 'true' : 'false');
    button.setAttribute('aria-label', shown ? 'Skrýt heslo' : 'Zobrazit heslo');
    button.title = shown ? 'Skrýt' : 'Zobrazit';
}
