<?php
/**
 * Výběr osoby z kontaktů s hledáním při psaní — formulář klienta (hlavní
 * kontakt) i Další kontakty v detailu klienta. Hledá se ve jméně, e-mailu,
 * telefonu i firmách osoby; nabídku kreslí a ovládá `contact-pick.js`.
 *
 * Odesílá se jen skryté `contact_id` (prázdné = nová osoba z polí pod
 * výběrem). Textové pole je jen na hledání a nemá jméno, takže se nikam
 * neposílá.
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\View\Form $form      formulář, do kterého výběr patří (chyby, obal pole)
 * @var string              $mode      edit | link (viz `contact-pick.js`)
 * @var string              $value     id vybrané osoby ('' = nová)
 * @var array<int, string>  $options   id => „Jméno Příjmení · e-mail" (popisek vybrané osoby)
 * @var string              $json      údaje osob pro skript (`ContactController::pickData()`)
 * @var string              $clientId  klient, na jehož stránce výběr je ('' = nový klient)
 * @var string              $hint
 */
$fieldId = 'contact-pick-' . $mode;
$control = sprintf(
    '<div class="contact-pick" data-contact-pick="%1$s" data-contacts="%2$s" data-contact-client="%3$s">'
        . '<input type="hidden" name="contact_id" value="%4$s">'
        . '<input type="text" id="%5$s" class="form__control contact-pick__input" value="%6$s" placeholder="Hledat jméno, e-mail, telefon nebo firmu…"'
        . ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="%5$s-list" autocomplete="off" spellcheck="false">'
        . '<button type="button" class="btn btn--ghost btn--icon contact-pick__clear" title="Zrušit výběr — nová osoba" aria-label="Zrušit výběr — nová osoba"%7$s>%8$s</button>'
        . '<ul class="contact-pick__list" id="%5$s-list" role="listbox" aria-label="Osoby z kontaktů" hidden></ul>'
        . '</div>',
    $this->e($mode),
    $this->e($json),
    $this->e($clientId),
    $this->e($value),
    $this->e($fieldId),
    $this->e($options[(int) $value] ?? ''),
    $value === '' ? ' hidden' : '',
    get_icon('close', 'icon--sm'),
);
?>
<?= $form->wrap('contact_id', 'Osoba z kontaktů', $control, hint: $hint, id: $fieldId, class: 'form__field--caps') ?>
