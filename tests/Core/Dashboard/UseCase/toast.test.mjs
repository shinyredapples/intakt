import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadController } from './stimulus-controller.mjs';

const ToastController = await loadController('toast');

function shownToast(t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const toast = new ToastController();
    toast.element = new FakeElement('All projects paused');
    toast.connect();

    return toast;
}

test('a toast hides itself after five seconds', (t) => {
    const toast = shownToast(t);

    t.mock.timers.tick(4999);
    assert.equal(toast.element.hidden, false);
    t.mock.timers.tick(1);
    assert.equal(toast.element.hidden, true);
});

test('the pointer on a toast holds it', (t) => {
    const toast = shownToast(t);

    toast.pointerEnter();
    t.mock.timers.tick(60000);

    assert.equal(toast.element.hidden, false);
});

test('focus on a toast holds it', (t) => {
    const toast = shownToast(t);

    toast.focusIn();
    t.mock.timers.tick(60000);

    assert.equal(toast.element.hidden, false);
});

test('the pointer leaving a toast whose undo has focus still holds it', (t) => {
    const toast = shownToast(t);
    toast.pointerEnter();
    toast.focusIn();

    toast.pointerLeave();
    t.mock.timers.tick(60000);

    assert.equal(toast.element.hidden, false);
});

test('focus leaving a toast under the pointer still holds it', (t) => {
    const toast = shownToast(t);
    toast.focusIn();
    toast.pointerEnter();

    toast.focusOut();
    t.mock.timers.tick(60000);

    assert.equal(toast.element.hidden, false);
});

test('leaving a held toast gives it five more seconds', (t) => {
    const toast = shownToast(t);
    t.mock.timers.tick(4000);
    toast.pointerEnter();
    toast.focusIn();

    toast.pointerLeave();
    toast.focusOut();
    t.mock.timers.tick(4999);
    assert.equal(toast.element.hidden, false);
    t.mock.timers.tick(1);
    assert.equal(toast.element.hidden, true);
});

test('undo hides the toast at once', (t) => {
    const toast = shownToast(t);

    toast.dismiss();

    assert.equal(toast.element.hidden, true);
});

test('a toast that leaves the page stops its timer', (t) => {
    const toast = shownToast(t);

    toast.disconnect();
    t.mock.timers.tick(5000);

    assert.equal(toast.element.hidden, false);
});
