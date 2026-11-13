const FOLD_BUTTON = '.col-fold, .col-hide';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and still shows the board. Listens on the page once, so a board a visit or a live update puts in later is covered too.
export function foldColumns(page) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(FOLD_BUTTON);
        if (button) {
            fold(button);
        }
    });
}

function fold(button) {
    const column = button.closest('.col');
    const open = button.classList.contains('col-fold');

    column.classList.toggle('collapsed', !open);
    column.querySelector('.col-fold').setAttribute('aria-expanded', String(open));
    // The pressed button disappears with the state it changed, so the focus goes to the one that changes it back.
    column.querySelector(open ? '.col-hide' : '.col-fold').focus();
}
