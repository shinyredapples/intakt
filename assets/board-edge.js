const WRAP = '.board-wrap';
// The mock's slack, so a board scrolled to within a rounding error of its end fades no edge.
const SLACK = 4;

// Not a Stimulus controller, for the reason assets/column-fold.js gives. A fold, a pushed column (the layout's filter runs after it) and a resize change how far the board reaches, so each marks the edges again.
export function markBoardEdges(page, view) {
    const markEveryBoard = () => page.querySelectorAll(WRAP).forEach(mark);

    // Captured, because a scroll does not bubble from the board to the page.
    page.addEventListener('scroll', (event) => {
        const wrap = event.target?.closest?.(WRAP);
        if (wrap) {
            mark(wrap);
        }
    }, { capture: true, passive: true });
    page.addEventListener('click', markEveryBoard);
    page.addEventListener('intakt:project-shown', markEveryBoard);
    view.addEventListener('resize', markEveryBoard);
    markEveryBoard();
}

function mark(wrap) {
    const board = wrap.querySelector('.board');

    wrap.classList.toggle('more-right', board.scrollLeft + board.clientWidth < board.scrollWidth - SLACK);
    wrap.classList.toggle('more-left', board.scrollLeft > SLACK);
}
