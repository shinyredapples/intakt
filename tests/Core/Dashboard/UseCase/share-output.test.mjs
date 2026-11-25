import { test } from 'node:test';
import assert from 'node:assert/strict';
import { root } from './stimulus-controller.mjs';

const { followShares } = await import(new URL('assets/share-output.js', root));

class FakeSlider {
    constructor(value, output) {
        this.value = value;
        this.dataset = output === null ? {} : { shareOutput: output };
    }

    closest(selector) {
        return 'shareOutput' in this.dataset && selector === 'input[type="range"][data-share-output]' ? this : null;
    }
}

class FakePage {
    listeners = new Map();
    elements = new Map();

    addEventListener(type, listener) {
        assert.equal(this.listeners.has(type), false);
        this.listeners.set(type, listener);
    }

    getElementById(id) {
        return this.elements.get(id) ?? null;
    }

    dispatch(type, event) {
        this.listeners.get(type)?.(event);
    }
}

function pageWithAShareOutput() {
    const page = new FakePage();
    const output = { textContent: '20%' };
    page.elements.set('share-percent', output);
    followShares(page);

    return { page, output };
}

test('moving the slider shows its share in percent beside it', () => {
    const { page, output } = pageWithAShareOutput();

    page.dispatch('input', { target: new FakeSlider('35', 'share-percent') });

    assert.equal(output.textContent, '35%');
});

test('typing in another field leaves the share as it was', () => {
    const { page, output } = pageWithAShareOutput();

    page.dispatch('input', { target: new FakeSlider('35', null) });

    assert.equal(output.textContent, '20%');
});

test('a slider naming an output the page lacks changes nothing and does not fail', () => {
    const { page, output } = pageWithAShareOutput();

    page.dispatch('input', { target: new FakeSlider('35', 'missing') });

    assert.equal(output.textContent, '20%');
});
