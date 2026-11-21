const BOARD = '.board';
const MAP = '.stagemap';
const SWITCHER = '.colswitch';
const JUMP = '.sm-seg, .colswitch button';
const COLUMN = ':scope > .col';
// The slack assets/board-edge.js allows, so a column within a rounding error of the edge counts as in view.
const SLACK = 4;
// The mock's pause after the last scroll event of a swipe, so the switcher follows where the board settled rather than every frame on the way.
const SETTLE_MS = 80;
// The switcher's own inset, so the pressed button keeps the same room on its left as the first one has.
const SWITCHER_INSET = 16;

// Not a Stimulus controller, for the reason assets/column-fold.js gives. The column map (DESIGN.md, Board edge and stage map) and the phone's switcher (Phone patterns) both jump to a column; a fold (by a click, or by Escape in assets/column-fold.js, which runs first because the layout starts it first), a resize and the layout's project filter change how far the board reaches, so each measures the map again.
export function mapBoardColumns(page, view) {
    let settling = null;
    const measure = () => markMap(page);
    const stopSettling = () => {
        view.clearTimeout(settling);
        settling = null;
    };

    page.addEventListener('click', (event) => {
        const jump = event.target?.closest?.(JUMP);
        if (jump) {
            jumpTo(page, view, jump);
        }
        measure();
    });
    page.addEventListener('keydown', measure);
    // Captured, because a scroll does not bubble from the board to the page.
    page.addEventListener('scroll', (event) => {
        const board = page.querySelector(BOARD);
        if (!board || event.target !== board) {
            return;
        }
        measure();
        stopSettling();
        settling = view.setTimeout(() => {
            settling = null;
            // A visit can replace the board before the swipe settles, and the old board's position says nothing about the new one's switcher.
            if (board.isConnected && page.querySelector(BOARD) === board) {
                pressSettledColumn(page, board);
            }
        }, SETTLE_MS);
    }, { capture: true, passive: true });
    page.addEventListener('turbo:before-render', stopSettling);
    page.addEventListener('intakt:project-shown', measure);
    view.addEventListener('resize', measure);
    measure();
}

function columnsOf(board) {
    return [...board.querySelectorAll(COLUMN)];
}

// Measured from the first column, so a phone board's inset, which its snapping keeps, is never scrolled past.
function startOf(column, columns) {
    return column.offsetLeft - columns[0].offsetLeft;
}

// The focus follows the jump, so the next Tab reaches the column's first card or strip rather than the first column's; preventScroll leaves the motion to the scrolls.
function jumpTo(page, view, jump) {
    const id = jump.getAttribute('aria-controls');
    const column = id ? page.getElementById(id) : null;
    const board = column?.closest(BOARD);
    if (!board) {
        return;
    }
    const behavior = view.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    board.scrollTo({ left: startOf(column, columnsOf(board)), behavior });
    // The phone's switcher stays pinned while the page scrolls down a long column, so a shorter column the reader jumps to would start, or even end, above the viewport; the page comes back up until the column starts just below the switcher.
    const bar = jump.closest(`${MAP}, ${SWITCHER}`);
    const hidden = bar ? bar.getBoundingClientRect().bottom - column.getBoundingClientRect().top : 0;
    if (hidden > 0) {
        view.scrollBy({ top: -hidden, behavior });
    }
    column.focus({ preventScroll: true });
}

function markMap(page) {
    const board = page.querySelector(BOARD);
    const map = page.querySelector(MAP);
    if (!board || !map) {
        return;
    }
    const wider = board.scrollWidth > board.clientWidth + SLACK;
    map.hidden = !wider;
    if (!wider) {
        return;
    }
    const columns = columnsOf(board);
    const left = board.scrollLeft;
    const right = left + board.clientWidth;
    const shown = columns.map((column) => {
        const start = startOf(column, columns);

        return start >= left - SLACK && start + column.offsetWidth <= right + SLACK;
    });
    map.querySelectorAll('.sm-seg').forEach((segment, i) => segment.classList.toggle('in', shown[i] ?? false));
    const first = shown.indexOf(true) + 1;
    map.querySelector('.sm-text').textContent = first > 0 ? `Columns ${first} to ${shown.lastIndexOf(true) + 1} of ${columns.length}` : '';
}

function pressSettledColumn(page, board) {
    const switcher = page.querySelector(SWITCHER);
    const columns = columnsOf(board);
    if (!switcher || columns.length === 0) {
        return;
    }
    const distances = columns.map((column) => Math.abs(startOf(column, columns) - board.scrollLeft));
    const settled = distances.indexOf(Math.min(...distances));
    const buttons = [...switcher.querySelectorAll('button')];
    buttons.forEach((button, i) => button.setAttribute('aria-pressed', String(i === settled)));
    if (buttons[settled]) {
        switcher.scrollLeft = Math.max(0, buttons[settled].offsetLeft - switcher.offsetLeft - SWITCHER_INSET);
    }
}
