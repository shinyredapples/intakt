const CAPTION = 'tour-caption';
const PAUSE = '[data-tour-pause]';
const CLOSE = '[data-tour-close]';
const CLOSED_KEY = 'intakt-tour';
const ENDED_KEY = 'intakt-tour-ended';
const FOCUS = 'data-tour-focus';
const FOLLOW_MARK = 'data-tour-follow';
const FOLLOW = `[${FOLLOW_MARK}]`;
// Only Play posts to the demo page, and a refused Play flashes an error, so a start line there is the page a started run's redirect reached. A later request finds the flash spent, and a page Turbo restores from its cache has neither mark: the flash lines are data-turbo-temporary, and takePlay() removes the follow mark when it takes the page.
const STARTED = 'main .flash-success';
// Long enough to read the last line once, short enough that an ended tour never stays on the pages after it.
const LAST_STAGE_MS = 8000;

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and its caption must still close. Listens on the page once, so a caption a live update or a visit puts in later is covered too.
export function captionTour(page, storage) {
    let held = null;
    // The body of the page Play's own redirect reached; Turbo gives each visit a new body, so a later visit to the same page is not it.
    const tour = { followOn: null };

    page.addEventListener('click', (event) => {
        const pause = event.target?.closest?.(PAUSE);
        if (pause) {
            const resumed = togglePause(pause);
            const shown = held;
            // A pause starts empty, because a stage held on an earlier page would bring back an older stage on resume.
            held = null;
            if (resumed) {
                Promise.resolve(shown?.render(shown.stream)).then(() => pointAt(page, storage, tour));
            }
            return;
        }
        if (event.target?.closest?.(CLOSE)) {
            close(page, storage, null);
        }
    });
    // Turbo appends a stream to the document element, so only the document sees it.
    page.addEventListener('turbo:before-stream-render', (event) => {
        if (event.target.getAttribute('target') !== CAPTION || !isPaused(page)) {
            // Any update can replace the element a stage points at, so the mark is drawn again after each one.
            const render = event.detail.render;
            event.detail.render = (stream) => Promise.resolve(render(stream)).then(() => pointAt(page, storage, tour));
            return;
        }
        event.preventDefault();
        held = { render: event.detail.render, stream: event.target };
    });
    // A visit Turbo makes runs no module again, so the new page's element is marked on its load.
    page.addEventListener('turbo:load', () => {
        takePlay(page, tour);
        pointAt(page, storage, tour);
    });
    takePlay(page, tour);
    pointAt(page, storage, tour);
}

// Only the page a started run's redirect reached follows, so a stalled, refused or later visit to the demo page never sends the visitor on.
function takePlay(page, tour) {
    const mark = page.querySelector?.(FOLLOW);
    if (!mark || !page.querySelector?.(STARTED)) {
        return;
    }
    tour.followOn = page.body;
    // Taken once: the copy of this page Turbo caches on the way out must not be taken again when Back restores it.
    mark.removeAttribute?.(FOLLOW_MARK);
}

// Marks the element the stage talks about (the caption names it in data-tour-at), scrolls it into view when it is new, follows the tour from the page Play reached to the review, and closes the tour a moment after its last stage.
function pointAt(page, storage, tour) {
    const caption = page.getElementById?.(CAPTION);
    const at = caption?.getAttribute?.('data-tour-at');
    if (!at) {
        return;
    }
    if (isClosed(page)) {
        if (!startsAfterTheEnd(caption, storage)) {
            unmark(page, null);
            return;
        }
        open(page, storage);
    }
    const review = caption.querySelector('a.tour-review')?.getAttribute('href');
    if (review && tour.followOn === page.body && !caption.hasAttribute('data-tour-last') && !isPaused(page)) {
        tour.followOn = null;
        page.defaultView?.location.assign(review);
        return;
    }
    let target = null;
    try {
        target = page.querySelector?.(at) ?? null;
    } catch {}
    unmark(page, target);
    if (target && target.getAttribute(FOCUS) !== caption.getAttribute('data-stage')) {
        target.setAttribute(FOCUS, caption.getAttribute('data-stage'));
        const still = page.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView?.({ block: 'center', behavior: still ? 'auto' : 'smooth' });
    }
    if (caption.hasAttribute('data-tour-last') && !caption.hasAttribute('data-closing') && !isPaused(page)) {
        closeLater(page, storage, caption);
    }
}

// Tied to the caption element it was armed for, so a caption a live update put in meanwhile, even for the same stage, keeps its own full moment; a paused caption is armed again on resume.
function closeLater(page, storage, caption) {
    caption.setAttribute('data-closing', '');
    page.defaultView?.setTimeout(() => {
        if (isClosed(page) || page.getElementById(CAPTION) !== caption) {
            return;
        }
        if (isPaused(page)) {
            caption.removeAttribute('data-closing');
            return;
        }
        close(page, storage, caption.getAttribute('data-round'));
    }, LAST_STAGE_MS);
}

// Only the tour's own end gives way to another round, at whichever stage a page first shows it; a tour the visitor closed stays closed for the tab.
function startsAfterTheEnd(caption, storage) {
    let ended = null;
    try {
        ended = storage.getItem(ENDED_KEY);
    } catch {}

    return ended !== null && ended !== caption.getAttribute('data-round');
}

function unmark(page, kept) {
    for (const marked of page.querySelectorAll?.(`[${FOCUS}]`) ?? []) {
        if (marked !== kept) {
            marked.removeAttribute(FOCUS);
        }
    }
}

// Only the newest stage is kept while paused, because it already holds everything the ones before it said.
function togglePause(pause) {
    const paused = pause.getAttribute('aria-pressed') !== 'true';
    pause.setAttribute('aria-pressed', String(paused));
    pause.textContent = paused ? 'Resume' : 'Pause';

    return !paused;
}

function isPaused(page) {
    return page.getElementById(CAPTION)?.querySelector(PAUSE)?.getAttribute('aria-pressed') === 'true';
}

function isClosed(page) {
    return page.documentElement.dataset.tour === 'closed';
}

// Kept for the tab only, so a new visit to the demo shows the caption again.
function close(page, storage, endedRound) {
    page.documentElement.dataset.tour = 'closed';
    unmark(page, null);
    write(storage, CLOSED_KEY, 'closed');
    if (endedRound === null) {
        forget(storage, ENDED_KEY);
    } else {
        write(storage, ENDED_KEY, endedRound);
    }
}

function open(page, storage) {
    delete page.documentElement.dataset.tour;
    forget(storage, CLOSED_KEY);
    forget(storage, ENDED_KEY);
}

function write(storage, key, value) {
    try {
        storage.setItem(key, value);
    } catch {}
}

function forget(storage, key) {
    try {
        storage.removeItem(key);
    } catch {}
}
