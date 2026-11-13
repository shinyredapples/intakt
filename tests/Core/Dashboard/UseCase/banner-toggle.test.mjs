import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { toggleBanners } = await import(new URL('assets/banner-toggle.js', root));

class FakeToggle extends FakeElement {
    constructor(banner) {
        super('Pull request 7 of alpha failed');
        this.banner = banner;
        this.setAttribute('aria-expanded', 'false');
    }

    closest(selector) {
        return { '.banner-toggle': this, '.banner': this.banner }[selector];
    }
}

class FakePage {
    addEventListener(type, listener) {
        assert.equal(type, 'click');
        this.click = listener;
    }
}

function collapsedBannerOnABoard() {
    const page = new FakePage();
    toggleBanners(page);
    const banner = new FakeElement();

    return { page, banner, toggle: new FakeToggle(banner) };
}

test('the toggle opens its banner and says it is expanded', () => {
    const { page, banner, toggle } = collapsedBannerOnABoard();

    page.click({ target: toggle });

    assert.equal(banner.classList.contains('open'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
});

test('a second tap closes the banner again', () => {
    const { page, banner, toggle } = collapsedBannerOnABoard();
    page.click({ target: toggle });

    page.click({ target: toggle });

    assert.equal(banner.classList.contains('open'), false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});

test('a click outside a banner toggle opens no banner', () => {
    const { page, banner, toggle } = collapsedBannerOnABoard();

    page.click({ target: { closest: () => null } });
    page.click({ target: null });

    assert.equal(banner.classList.contains('open'), false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});
