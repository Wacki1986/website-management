/**
 * Vlastní úkoly v zápisu servisu: „Přidat další úkol“ a křížek u úkolu.
 *
 * Bez skriptu má každý seznam na konci jeden prázdný řádek a úkol se
 * odebere smazáním textu. Se skriptem prázdný řádek zmizí, přibude
 * tlačítko a řádky se přidávají klonem `<template data-task-template>`.
 * Názvy polí dostanou nový klíč (`extra[druh][tN][…]`) — na pořadí
 * klíčů nezáleží, controller je čte popořadě.
 */
export function initServiceTasks() {
    const template = document.querySelector('template[data-task-template]');

    if (!template) {
        return;
    }

    let counter = 0;

    document.querySelectorAll('[data-service-tasks]').forEach((box) => {
        const kind = box.dataset.serviceTasks;
        const list = box.querySelector('[data-task-list]');
        const add = box.querySelector('[data-task-add]');

        box.querySelector('[data-task-blank]')?.remove();
        box.querySelectorAll('[data-task-remove]').forEach((button) => { button.hidden = false; });
        add.hidden = false;

        add.addEventListener('click', () => {
            const row = template.content.firstElementChild.cloneNode(true);
            const key = `t${++counter}`;

            row.querySelector('[data-task-done]').name = `extra[${kind}][${key}][done]`;
            row.querySelector('[data-task-label]').name = `extra[${kind}][${key}][label]`;
            list.append(row);
            row.querySelector('[data-task-label]').focus();
        });

        list.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-task-remove]');

            if (remove) {
                remove.closest('[data-task]').remove();
                add.focus();
            }
        });

        // Enter v textu úkolu přidá další řádek místo odeslání formuláře.
        list.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target.matches('.task-list__input')) {
                event.preventDefault();
                add.click();
            }
        });
    });
}
