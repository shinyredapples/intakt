const BOARD = '.board';
const MAP = '.stagemap';
const SWITCHER = '.colswitch';
const JUMP = '.sm-seg, .colswitch button';
const COLUMN = ':scope > .col';
const BOTTOM_BAR = '.rail-in';
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
    // A page Turbo restores from its cache keeps the pressed button it was left with, while its board starts at its first column again, so every render presses the column the board shows.
    const settle = () => {
        measure();
        const board = page.querySelector(BOARD);
        if (board) {
            pressSettledColumn(page, board);
        }
    };
    page.addEventListener('turbo:render', settle);
    page.addEventListener('turbo:load', settle);
    settle();
}

function columnsOf(board) {
    return [...board.querySelectorAll(COLUMN)];
}

// Measured from the first column, so a phone board's inset, which its snapping keeps, is never scrolled past.
function startOf(column, columns) {
    return column.offsetLeft - columns[0].offsetLeft;
}

// The focus follows the jump, so the next Tab reaches the column's first control (Hide on an open Backlog or Done, a card, or the strip) rather than the first column's; preventScroll leaves the motion to the scrolls.
function jumpTo(page, view, jump) {
    const id = jump.getAttribute('aria-controls');
    const column = id ? page.getElementById(id) : null;
    const board = column?.closest(BOARD);
    if (!board) {
        return;
    }
    const behavior = view.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    board.scrollTo({ left: startOf(column, columnsOf(board)), behavior });
    // The phone's switcher stays pinned while the page scrolls down a long column, so a shorter column the reader jumps to would start, or even end, above the viewport, and a switcher scrolled near the bottom leaves the column behind the bottom bar; either way the page moves until the column starts just below the switcher.
    const bar = jump.closest(`${MAP}, ${SWITCHER}`);
    if (bar) {
        const below = pinnedBottom(view, bar);
        const top = column.getBoundingClientRect().top;
        if (top < below || top >= visibleBottom(page, view, below)) {
            view.scrollBy({ top: top - below, behavior });
        }
    }
    column.focus({ preventScroll: true });
}

// A sticky switcher still low on the page moves up with it until it pins, so the column is aimed at where the switcher's bottom will be once pinned; the map scrolls with the page.
function pinnedBottom(view, bar) {
    const rect = bar.getBoundingClientRect();
    const style = view.getComputedStyle(bar);

    return 'sticky' === style.position ? Number.parseFloat(style.top) + rect.height : rect.bottom;
}

// On a phone the rail is a fixed bar along the bottom of the viewport and covers what lies behind it; on a wider screen it runs down the side, starting above the map, and covers nothing below.
function visibleBottom(page, view, below) {
    const rail = page.querySelector(BOTTOM_BAR)?.getBoundingClientRect();

    return rail && rail.top > below && rail.bottom >= view.innerHeight - SLACK ? rail.top : view.innerHeight;
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
    // A switcher button that holds the keyboard focus keeps the strip where it is, so a swipe never scrolls the focused button out of view (WCAG 2.4.11).
    if (buttons[settled] && !buttons.includes(page.activeElement)) {
        switcher.scrollLeft = Math.max(0, buttons[settled].offsetLeft - SWITCHER_INSET);
    }
}
