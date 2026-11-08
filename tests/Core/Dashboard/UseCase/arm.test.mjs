import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeClick, FakeElement, loadController } from './stimulus-controller.mjs';

const ArmController = await loadController('arm');

function dangerButton() {
    const controller = new ArmController();
    controller.element = new FakeElement('Pause everything');
    controller.confirmValue = 'Click again to pause';

    return controller;
}

test('the first click is held back and asks to click again', () => {
    const button = dangerButton();
    const click = new FakeClick();

    button.click(click);

    assert.equal(click.defaultPrevented, true);
    assert.equal(click.propagationStopped, true);
    assert.equal(button.element.hasAttribute('data-armed'), true);
    assert.equal(button.element.textContent, 'Click again to pause');
});

test('the second click goes through and restores the label', () => {
    const button = dangerButton();
    button.click(new FakeClick());
    const second = new FakeClick();

    button.click(second);

    assert.equal(second.defaultPrevented, false);
    assert.equal(second.propagationStopped, false);
    assert.equal(button.element.hasAttribute('data-armed'), false);
    assert.equal(button.element.textContent, 'Pause everything');
});

test('an armed button disarms itself after four seconds', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const button = dangerButton();
    button.click(new FakeClick());

    t.mock.timers.tick(3999);
    assert.equal(button.element.hasAttribute('data-armed'), true);
    t.mock.timers.tick(1);
    assert.equal(button.element.hasAttribute('data-armed'), false);
    assert.equal(button.element.textContent, 'Pause everything');
});

test('a click after the button disarmed itself is held back again', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const button = dangerButton();
    button.click(new FakeClick());
    t.mock.timers.tick(4000);
    const late = new FakeClick();

    button.click(late);

    assert.equal(late.defaultPrevented, true);
    assert.equal(button.element.textContent, 'Click again to pause');
});

test('an armed button that leaves the page comes back unarmed', () => {
    const button = dangerButton();
    button.click(new FakeClick());

    button.disconnect();

    assert.equal(button.element.hasAttribute('data-armed'), false);
    assert.equal(button.element.textContent, 'Pause everything');
});

test('an unarmed button that leaves the page keeps its label', () => {
    const button = dangerButton();

    button.disconnect();

    assert.equal(button.element.textContent, 'Pause everything');
});
