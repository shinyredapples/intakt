const FOLD_BUTTON = '.col-fold, .col-hide';
const WORKING = 'details.working';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and still shows the board. Listens on the page once, so a board a visit or a live update puts in later is covered too.
export function foldColumns(page) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(FOLD_BUTTON);
        if (button) {
            fold(button);
        }
    });
    page.addEventListener('keydown', foldOnEscape);
    page.addEventListener('turbo:before-stream-render', (event) => keepWorkingOpen(page, event));
}

// A pushed Who is working comes closed, so one this tab opened stays open across the push (#1461).
function keepWorkingOpen(page, event) {
    if (event.target?.getAttribute?.('target') !== 'working' || !page.querySelector(WORKING)?.open) {
        return;
    }
    const render = event.detail.render;
    event.detail.render = (stream) => {
        render(stream);
        const pushed = page.querySelector(WORKING);
        if (pushed) {
            pushed.open = true;
        }
    };
}

// Escape folds back what the board's keyboard walk-through opened (#1460, D6): Who is working, or a column that opened from its strip. The focus goes to the control that opens it again, so the next Tab goes on from there.
function foldOnEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }
    const working = event.target?.closest?.(WORKING);
    if (working?.open) {
        event.preventDefault();
        working.open = false;
        working.querySelector('summary').focus();

        return;
    }
    const column = event.target?.closest?.('.col');
    const hide = column?.querySelector('.col-hide');
    // A phone shows no Hide, because its columns never fold (DESIGN.md, Phone patterns).
    if (!hide || column.classList.contains('collapsed') || hide.getClientRects().length === 0) {
        return;
    }
    event.preventDefault();
    fold(hide);
}

function fold(button) {
    const column = button.closest('.col');
    const open = button.classList.contains('col-fold');

    column.classList.toggle('collapsed', !open);
    column.querySelector('.col-fold').setAttribute('aria-expanded', String(open));
    // The pressed button disappears with the state it changed, so the focus goes to the one that changes it back.
    column.querySelector(open ? '.col-hide' : '.col-fold').focus();
}
