import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeClick, FakeElement, loadController } from './stimulus-controller.mjs';

const DisclosureController = await loadController('disclosure');

function collapsedBanner() {
    const toggle = new FakeElement('field-notes is paused');
    toggle.setAttribute('aria-expanded', 'false');
    const banner = new DisclosureController();
    banner.element = new FakeElement();

    return { banner, toggle };
}

test('the toggle opens the banner and says it is expanded', () => {
    const { banner, toggle } = collapsedBanner();

    banner.toggle(new FakeClick(toggle));

    assert.equal(banner.element.classList.contains('open'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
});

test('a second press closes the banner again', () => {
    const { banner, toggle } = collapsedBanner();
    banner.toggle(new FakeClick(toggle));

    banner.toggle(new FakeClick(toggle));

    assert.equal(banner.element.classList.contains('open'), false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});
