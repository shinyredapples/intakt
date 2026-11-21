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

// Not a Stimulus controller, for the reason assets/column-fold.js gives. The column map (DESIGN.md, Board edge and stage map) and the phone's switcher (Phone patterns) both jump to a column; a fold, a resize and the layout's project filter change how far the board reaches, so each measures the map again.
export function mapBoardColumns(page, view) {
    let settling = null;
    const measure = () => markMap(page);

    page.addEventListener('click', (event) => {
        const jump = event.target?.closest?.(JUMP);
        if (jump) {
            jumpTo(page, view, jump.getAttribute('aria-controls'));
        }
        measure();
    });
    // Captured, because a scroll does not bubble from the board to the page.
    page.addEventListener('scroll', (event) => {
        const board = page.querySelector(BOARD);
        if (!board || event.target !== board) {
            return;
        }
        measure();
        view.clearTimeout(settling);
        settling = view.setTimeout(() => pressSettledColumn(page, board), SETTLE_MS);
    }, { capture: true, passive: true });
    page.addEventListener('intakt:project-shown', measure);
    page.addEventListener('turbo:before-stream-render', (event) => keepPressedColumn(page, event));
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

// The focus follows the jump, so the next Tab reaches the column's first card or strip rather than the first column's; preventScroll leaves the motion to the scroll.
function jumpTo(page, view, id) {
    const column = id ? page.getElementById(id) : null;
    const board = column?.closest(BOARD);
    if (!board) {
        return;
    }
    const still = view.matchMedia('(prefers-reduced-motion: reduce)').matches;
    board.scrollTo({ left: startOf(column, columnsOf(board)), behavior: still ? 'auto' : 'smooth' });
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

// A pushed switcher comes with its first column pressed and scrolled to its start, so the column this tab shows is pressed again and the row kept where it was.
function keepPressedColumn(page, event) {
    if (event.target?.getAttribute?.('target') !== 'colswitch') {
        return;
    }
    const shown = page.querySelector(SWITCHER);
    if (!shown) {
        return;
    }
    const pressed = [...shown.querySelectorAll('button')].findIndex((button) => button.getAttribute('aria-pressed') === 'true');
    const scrolled = shown.scrollLeft;
    const render = event.detail.render;
    event.detail.render = (stream) => {
        render(stream);
        const pushed = page.querySelector(SWITCHER);
        pushed?.querySelectorAll('button').forEach((button, i) => button.setAttribute('aria-pressed', String(i === pressed)));
        if (pushed) {
            pushed.scrollLeft = scrolled;
        }
    };
}
