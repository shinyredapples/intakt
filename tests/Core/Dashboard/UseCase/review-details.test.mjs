import { test } from 'node:test';
import assert from 'node:assert/strict';
import { root } from './stimulus-controller.mjs';

const { openReviewDetails } = await import(new URL('assets/review-details.js', root));

const DETAILS = 'details.review-details';
const WIDE = '(min-width: 761px)';

class FakeView {
    constructor(wide) {
        this.matches = wide;
    }

    matchMedia(query) {
        assert.equal(query, WIDE);

        return this;
    }
}

class FakePage {
    listeners = new Map();

    constructor() {
        this.details = [{ open: false }];
    }

    querySelectorAll(selector) {
        assert.equal(selector, DETAILS);

        return this.details;
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }
}

test('a wide screen opens the details on load', () => {
    const page = new FakePage();

    openReviewDetails(page, new FakeView(true));

    assert.equal(page.details[0].open, true);
});

test('a phone keeps the details closed on load', () => {
    const page = new FakePage();

    openReviewDetails(page, new FakeView(false));

    assert.equal(page.details[0].open, false);
});

test('a wide screen opens the details again after a Turbo visit renders them closed', () => {
    const page = new FakePage();
    openReviewDetails(page, new FakeView(true));
    page.details = [{ open: false }];

    page.listeners.get('turbo:load')();

    assert.equal(page.details[0].open, true);
});

test('a reader who closes the details on a wide screen keeps them closed while the page stays', () => {
    const page = new FakePage();
    openReviewDetails(page, new FakeView(true));

    page.details[0].open = false;

    assert.deepEqual([...page.listeners.keys()].sort(), ['turbo:load', 'turbo:render']);
    assert.equal(page.details[0].open, false);
});
