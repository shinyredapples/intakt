import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeClick, FakeElement, root } from './stimulus-controller.mjs';

const { holdBackDangerButtons } = await import(new URL('assets/arm.js', root));

class FakePage {
    listeners = new Map();
    buttons = [];

    addEventListener(type, listener, capture = false) {
        this.listeners.set(type, { listener, capture });
    }

    querySelectorAll(selector) {
        assert.equal(selector, '[data-armed]');

        return this.buttons.filter((button) => button.hasAttribute('data-armed'));
    }

    click(target) {
        const click = new FakeClick();
        click.target = target;
        this.listeners.get('click').listener(click);

        return click;
    }

    cacheForGoingBack() {
        this.listeners.get('turbo:before-cache').listener();
    }
}

class FakeButton extends FakeElement {
    closest(selector) {
        assert.equal(selector, 'button[data-arm-confirm]');

        return this.hasAttribute('data-arm-confirm') ? this : null;
    }
}

function pageWithADangerButton() {
    const page = new FakePage();
    const button = new FakeButton('Pause everything');
    button.setAttribute('data-arm-confirm', 'Click again to pause');
    page.buttons.push(button);
    holdBackDangerButtons(page);

    return { page, button };
}

test('the page is listened to in the capture phase', () => {
    const { page } = pageWithADangerButton();

    assert.equal(page.listeners.get('click').capture, true);
});

test('the first click is held back and asks to click again', () => {
    const { page, button } = pageWithADangerButton();

    const click = page.click(button);

    assert.equal(click.defaultPrevented, true);
    assert.equal(click.propagationStopped, true);
    assert.equal(button.hasAttribute('data-armed'), true);
    assert.equal(button.textContent, 'Click again to pause');
});

test('the second click goes through and restores the label', () => {
    const { page, button } = pageWithADangerButton();
    page.click(button);

    const second = page.click(button);

    assert.equal(second.defaultPrevented, false);
    assert.equal(second.propagationStopped, false);
    assert.equal(button.hasAttribute('data-armed'), false);
    assert.equal(button.textContent, 'Pause everything');
});

test('a click on another button goes through', () => {
    const { page } = pageWithADangerButton();
    const other = new FakeButton('Start');

    const click = page.click(other);

    assert.equal(click.defaultPrevented, false);
    assert.equal(other.textContent, 'Start');
});

test('an armed button disarms itself after four seconds', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { page, button } = pageWithADangerButton();
    page.click(button);

    t.mock.timers.tick(3999);
    assert.equal(button.hasAttribute('data-armed'), true);
    t.mock.timers.tick(1);
    assert.equal(button.hasAttribute('data-armed'), false);
    assert.equal(button.textContent, 'Pause everything');
});

test('a click after the button disarmed itself is held back again', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { page, button } = pageWithADangerButton();
    page.click(button);
    t.mock.timers.tick(4000);

    const late = page.click(button);

    assert.equal(late.defaultPrevented, true);
    assert.equal(button.textContent, 'Click again to pause');
});

test('an armed button on a page kept for going back comes back unarmed', () => {
    const { page, button } = pageWithADangerButton();
    page.click(button);

    page.cacheForGoingBack();

    assert.equal(button.hasAttribute('data-armed'), false);
    assert.equal(button.textContent, 'Pause everything');
});

test('an unarmed button on a page kept for going back keeps its label', () => {
    const { page, button } = pageWithADangerButton();

    page.cacheForGoingBack();

    assert.equal(button.textContent, 'Pause everything');
});
