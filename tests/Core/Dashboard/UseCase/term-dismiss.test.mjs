import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { dismissTermTips } = await import(new URL('assets/term-dismiss.js', root));

function pageWithATerm() {
    const listeners = {};
    const page = { addEventListener: (type, listener) => (listeners[type] = listener) };
    dismissTermTips(page);
    const term = new FakeElement();
    term.state = { hover: false, focus: false };
    term.matches = (selector) => (selector === ':hover' ? term.state.hover : term.state.focus);
    term.contains = (node) => node === term;
    const inside = { closest: (selector) => (selector === '.term' ? term : null) };

    return { listeners, term, inside };
}

test('Escape on a term marks its tip dismissed', () => {
    const { listeners, term, inside } = pageWithATerm();

    listeners.keydown({ key: 'Escape', target: inside });

    assert.equal(term.hasAttribute('data-dismissed'), true);
});

test('another key on a term leaves its tip open', () => {
    const { listeners, term, inside } = pageWithATerm();

    listeners.keydown({ key: 'Enter', target: inside });

    assert.equal(term.hasAttribute('data-dismissed'), false);
});

test('Escape outside a term changes nothing', () => {
    const { listeners, term } = pageWithATerm();

    listeners.keydown({ key: 'Escape', target: { closest: () => null } });
    listeners.keydown({ key: 'Escape', target: null });

    assert.equal(term.hasAttribute('data-dismissed'), false);
});

test('focus leaving a dismissed term re-arms its tip', () => {
    const { listeners, term, inside } = pageWithATerm();
    listeners.keydown({ key: 'Escape', target: inside });

    listeners.focusout({ target: inside });

    assert.equal(term.hasAttribute('data-dismissed'), false);
});

test('focus leaving a dismissed term under the pointer keeps its tip closed', () => {
    const { listeners, term, inside } = pageWithATerm();
    listeners.keydown({ key: 'Escape', target: inside });
    term.state.hover = true;

    listeners.focusout({ target: inside });

    assert.equal(term.hasAttribute('data-dismissed'), true);
});

test('the pointer leaving a dismissed term whose focus is gone re-arms its tip', () => {
    const { listeners, term, inside } = pageWithATerm();
    listeners.keydown({ key: 'Escape', target: inside });

    listeners.pointerout({ target: inside, relatedTarget: null });

    assert.equal(term.hasAttribute('data-dismissed'), false);
});

test('the pointer leaving a dismissed term that still has focus keeps its tip closed', () => {
    const { listeners, term, inside } = pageWithATerm();
    listeners.keydown({ key: 'Escape', target: inside });
    term.state.focus = true;

    listeners.pointerout({ target: inside, relatedTarget: null });

    assert.equal(term.hasAttribute('data-dismissed'), true);
});
