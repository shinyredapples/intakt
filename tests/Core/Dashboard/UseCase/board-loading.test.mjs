import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { showBoardLoading } = await import(new URL('assets/board-loading.js', root));

class FakeMain extends FakeElement {
    innerHTML = 'the page being left';
}

class FakeTemplate extends FakeElement {
    textContent = '<div class="board">skeleton</div>';

    constructor() {
        super();
        this.setAttribute('data-path', '/');
    }
}

class FakePage {
    baseURI = 'http://localhost:7780/reviews';
    listeners = {};
    main = new FakeMain();
    template = new FakeTemplate();

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    dispatch(type, detail = {}) {
        this.listeners[type]?.({ detail });
    }

    getElementById(id) {
        assert.equal(id, 'board-skeleton');

        return this.template;
    }

    querySelector(selector) {
        assert.equal(selector, 'main');

        return this.main;
    }
}

// Runs a timer only when the test says so, so a render can arrive before it.
class FakeView {
    timers = new Map();
    next = 1;

    setTimeout(callback, ms) {
        this.timers.set(this.next, { callback, ms });

        return this.next++;
    }

    clearTimeout(id) {
        this.timers.delete(id);
    }

    runTimers() {
        const due = [...this.timers.values()];
        this.timers.clear();
        due.forEach((timer) => timer.callback());
    }
}

function aPageWithTheLoader() {
    const page = new FakePage();
    const view = new FakeView();
    showBoardLoading(page, view);

    return { page, view, main: page.main };
}

test('a visit to the board that has not rendered yet shows the skeleton and marks the main region busy', () => {
    const { page, view, main } = aPageWithTheLoader();

    page.dispatch('turbo:visit', { url: 'http://localhost:7780/?board=1' });
    view.runTimers();

    assert.equal(main.innerHTML, '<div class="board">skeleton</div>');
    assert.equal(main.getAttribute('aria-busy'), 'true');
    assert.equal(main.classList.contains('wide'), true);
});

test('the skeleton waits a moment, so a board that renders at once never flashes it', () => {
    const { page, view, main } = aPageWithTheLoader();

    page.dispatch('turbo:visit', { url: 'http://localhost:7780/' });
    assert.equal([...view.timers.values()][0].ms, 150);
    page.dispatch('turbo:before-render');
    view.runTimers();

    assert.equal(main.innerHTML, 'the page being left');
    assert.equal(main.hasAttribute('aria-busy'), false);
});

test('a visit to another page shows no board skeleton', () => {
    const { page, view, main } = aPageWithTheLoader();

    page.dispatch('turbo:visit', { url: 'http://localhost:7780/reviews/round-1' });
    view.runTimers();

    assert.equal(main.innerHTML, 'the page being left');
});

test('a page without the skeleton template is left alone', () => {
    const { page, view, main } = aPageWithTheLoader();
    page.template = null;

    page.dispatch('turbo:visit', { url: 'http://localhost:7780/' });
    view.runTimers();

    assert.equal(main.innerHTML, 'the page being left');
});
