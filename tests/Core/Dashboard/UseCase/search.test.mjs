import { test } from 'node:test';
import assert from 'node:assert/strict';
import { root } from './stimulus-controller.mjs';

const { offerSearch } = await import(new URL('assets/search.js', root));

class FakeNode {
    attributes = new Map();
    hidden = false;
    inert = false;
    id = '';
    value = '';
    scrolledTo = null;

    constructor(page, tag, attributes = {}) {
        this.page = page;
        this.tag = tag;
        Object.entries(attributes).forEach(([name, value]) => this.attributes.set(name, value));
        this.dataset = { searchAction: attributes['data-search-action'], searchUrl: attributes['data-search-url'] };
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    // Only the selectors the script asks for, and only of the element itself.
    closest(selector) {
        const matches = {
            '.search-trigger': this.tag === 'trigger',
            '[data-search-action]': this.attributes.has('data-search-action'),
            '#search-results a[href]': this.tag === 'a' && this.attributes.has('href'),
        };

        return matches[selector] ? this : null;
    }

    focus() {
        this.page.activeElement = this;
    }

    click() {
        return this.page.click(this);
    }

    scrollIntoView(options) {
        this.scrolledTo = options;
    }
}

// The list box reads back the options of the fragment markup put into it, as a browser would parse them.
class FakeResults extends FakeNode {
    options = [];
    buttons = [];
    markup = '';

    set innerHTML(markup) {
        this.markup = markup;
        const parsed = [...markup.matchAll(/<(a|div|button) ([^>]*)>/g)]
            .map(([, tag, attributes]) => new FakeNode(this.page, tag, Object.fromEntries([...attributes.matchAll(/([\w-]+)="([^"]*)"/g)].map(([, name, value]) => [name, value]))));
        this.options = parsed.filter((node) => node.getAttribute('role') === 'option');
        this.buttons = parsed.filter((node) => node.tag === 'button');
    }

    get innerHTML() {
        return this.markup;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '[role="option"]');

        return this.options;
    }
}

class FakeKey {
    defaultPrevented = false;

    constructor(key, target, modifiers = {}) {
        this.key = key;
        this.target = target;
        this.ctrlKey = modifiers.ctrlKey ?? false;
        this.metaKey = modifiers.metaKey ?? false;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

const group = (...options) => options.map(([label, attributes]) => `<${attributes.includes('href') ? 'a' : 'div'} class="res" role="option" tabindex="-1" aria-selected="false" ${attributes}><span class="lbl">${label}</span></${attributes.includes('href') ? 'a' : 'div'}>`).join('');
const RECENT = group(['Round round-2', 'href="/reviews/round-2"'], ['Round round-1', 'href="/reviews/round-1"'], ['Switch light or dark theme', 'data-search-action="switch-theme"']);
const ALPHA = group(['alpha', 'href="/reviews?project=alpha"']);
const NONE = '<div class="none"><p><strong>No results for “zebra”</strong></p><form method="get" action="/search"><button class="btn secondary sm" type="submit" data-search-action="clear-search">Clear the search</button></form></div>';

class FakePage {
    listeners = new Map();
    asked = [];
    activeElement = null;
    baseURI = 'http://localhost/reviews';
    themeClicks = 0;

    constructor({ overlay = true } = {}) {
        this.trigger = new FakeNode(this, 'trigger');
        this.shell = new FakeNode(this, 'div');
        this.overlay = overlay ? Object.assign(new FakeNode(this, 'div', { 'data-search-url': '/search' }), { hidden: true }) : null;
        this.field = new FakeNode(this, 'input');
        this.results = new FakeResults(this, 'div');
        this.themeToggle = Object.assign(new FakeNode(this, 'button'), { click: () => this.themeClicks++ });
        this.trigger.focus();
    }

    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    querySelector(selector) {
        const elements = { '#search': this.overlay, '.shell': this.shell, '[data-theme-toggle]': this.themeToggle };
        if (this.overlay) {
            Object.assign(elements, { '#search-q': this.field, '#search-results': this.results });
        }

        return elements[selector] ?? null;
    }

    dispatch(type, event) {
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));

        return event;
    }

    press(key, modifiers = {}, target = this.activeElement) {
        return this.dispatch('keydown', new FakeKey(key, target, modifiers));
    }

    click(target) {
        let defaultPrevented = false;

        return this.dispatch('click', { target, preventDefault: () => (defaultPrevented = true), get defaultPrevented() { return defaultPrevented; } });
    }

    type(text) {
        this.field.value = text;
        this.dispatch('input', { target: this.field });
    }

    isOpen() {
        return this.overlay.hidden === false;
    }

    selected() {
        return this.results.options.find((option) => option.getAttribute('aria-selected') === 'true');
    }
}

// The fake network holds every answer until the test lets it go, so a test decides the order in which answers arrive.
function network(page) {
    const waiting = [];
    globalThis.fetch = (address, init) => {
        page.asked.push({ address: String(address), init });

        return new Promise((resolve, reject) => waiting.push({ address: String(address), resolve, reject }));
    };
    const settle = async (index, settleOne) => {
        settleOne(waiting[index]);
        await new Promise((resolve) => setImmediate(resolve));
    };

    return {
        answer: (index, markup, status = 200) => settle(index, (request) => request.resolve({ ok: status < 400, text: async () => markup })),
        fail: (index) => settle(index, (request) => request.reject(new TypeError('Failed to fetch'))),
        asked: () => waiting.map((request) => new URL(request.address).searchParams.get('q')),
    };
}

async function openPage(options = {}) {
    const page = new FakePage(options);
    const net = network(page);
    offerSearch(page);

    return { page, net };
}

async function openWithRecent() {
    const { page, net } = await openPage();
    page.press('k', { ctrlKey: true });
    await net.answer(0, RECENT);

    return { page, net };
}

test('ctrl k opens the search instead of the browser shortcut', async () => {
    const { page } = await openPage();

    const key = page.press('k', { ctrlKey: true });

    assert.ok(page.isOpen());
    assert.ok(key.defaultPrevented);
});

test('cmd k opens the search', async () => {
    const { page } = await openPage();

    page.press('K', { metaKey: true });

    assert.ok(page.isOpen());
});

test('k without a modifier is left to the page', async () => {
    const { page } = await openPage();

    const key = page.press('k');

    assert.ok(!page.isOpen());
    assert.ok(!key.defaultPrevented);
});

test('another shortcut with ctrl is left to the browser', async () => {
    const { page } = await openPage();

    const key = page.press('l', { ctrlKey: true });

    assert.ok(!page.isOpen());
    assert.ok(!key.defaultPrevented);
});

test('a page without the overlay leaves ctrl k to the browser', async () => {
    const { page } = await openPage({ overlay: false });

    const key = page.press('k', { ctrlKey: true });

    assert.ok(!key.defaultPrevented);
});

test('clicking the search button opens the search', async () => {
    const { page } = await openPage();

    page.click(page.trigger);

    assert.ok(page.isOpen());
});

test('opening puts the cursor in an empty field and asks for the empty query', async () => {
    const { page, net } = await openPage();
    page.field.value = 'old words';

    page.press('k', { ctrlKey: true });

    assert.equal(page.activeElement, page.field);
    assert.equal(page.field.value, '');
    assert.deepEqual(net.asked(), ['']);
    assert.equal(page.asked[0].address, 'http://localhost/search?q=');
    assert.equal(page.asked[0].init.headers.Accept, 'text/html');
});

test('opening makes the shell behind the overlay inert and closing gives it back', async () => {
    const { page } = await openWithRecent();
    assert.ok(page.shell.inert);

    page.press('Escape');

    assert.ok(!page.shell.inert);
});

test('the answer is shown with its first result selected', async () => {
    const { page } = await openWithRecent();

    assert.equal(page.results.innerHTML, RECENT);
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-2');
    assert.equal(page.field.getAttribute('aria-activedescendant'), page.selected().id);
    assert.equal(page.results.getAttribute('aria-busy'), null);
});

test('the list is marked busy while the answer is on its way', async () => {
    const { page } = await openPage();

    page.press('k', { ctrlKey: true });

    assert.equal(page.results.getAttribute('aria-busy'), 'true');
});

test('typing asks for the words in the field', async () => {
    const { page, net } = await openWithRecent();

    page.type('alpha beta');
    await net.answer(1, ALPHA);

    assert.deepEqual(net.asked(), ['', 'alpha beta']);
    assert.equal(page.results.innerHTML, ALPHA);
});

test('an older answer that arrives after a newer one is dropped', async () => {
    const { page, net } = await openWithRecent();
    page.type('al');
    page.type('alpha');

    await net.answer(2, ALPHA);
    await net.answer(1, RECENT);

    assert.equal(page.results.innerHTML, ALPHA);
});

test('an answer that arrives after the search closed is dropped', async () => {
    const { page, net } = await openWithRecent();
    page.type('alpha');
    page.press('Escape');

    await net.answer(1, ALPHA);

    assert.equal(page.results.innerHTML, RECENT);
});

test('arrow down selects the next result and arrow up the one before', async () => {
    const { page } = await openWithRecent();

    const down = page.press('ArrowDown');
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-1');
    assert.ok(down.defaultPrevented);

    page.press('ArrowUp');
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-2');
});

test('the arrows wrap around at either end of the list', async () => {
    const { page } = await openWithRecent();

    page.press('ArrowUp');
    assert.equal(page.selected().getAttribute('data-search-action'), 'switch-theme');

    page.press('ArrowDown');
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-2');
});

test('the selected result is the field active descendant and is scrolled into view', async () => {
    const { page } = await openWithRecent();

    page.press('ArrowDown');

    assert.equal(page.field.getAttribute('aria-activedescendant'), 'search-option-1');
    assert.equal(page.selected().id, 'search-option-1');
    assert.deepEqual(page.selected().scrolledTo, { block: 'nearest' });
    assert.equal(page.results.options.filter((option) => option.getAttribute('aria-selected') === 'true').length, 1);
});

test('enter opens the selected link and closes the search without holding the link back', async () => {
    const { page } = await openWithRecent();
    const opened = [];
    page.results.options[1].click = () => opened.push(page.click(page.results.options[1]));
    page.press('ArrowDown');

    const enter = page.press('Enter');

    assert.ok(enter.defaultPrevented);
    assert.equal(opened.length, 1);
    assert.equal(opened[0].defaultPrevented, false);
    assert.ok(!page.isOpen());
});

test('enter on the theme action closes the search and switches the theme', async () => {
    const { page } = await openWithRecent();
    page.press('ArrowUp');

    page.press('Enter');

    assert.equal(page.themeClicks, 1);
    assert.ok(!page.isOpen());
    assert.equal(page.activeElement, page.trigger);
});

test('clicking an action runs it instead of following the row', async () => {
    const { page } = await openWithRecent();

    const click = page.click(page.results.options[2]);

    assert.ok(click.defaultPrevented);
    assert.equal(page.themeClicks, 1);
});

test('escape closes the search and gives the focus back to where it was', async () => {
    const { page } = await openWithRecent();

    const key = page.press('Escape');

    assert.ok(!page.isOpen());
    assert.ok(key.defaultPrevented);
    assert.equal(page.activeElement, page.trigger);
});

test('ctrl k closes an open search', async () => {
    const { page } = await openWithRecent();

    page.press('k', { ctrlKey: true });

    assert.ok(!page.isOpen());
});

test('a click on the scrim closes the search', async () => {
    const { page } = await openWithRecent();

    page.click(page.overlay);

    assert.ok(!page.isOpen());
});

test('the keys do nothing while the search is closed', async () => {
    const { page } = await openPage();

    for (const name of ['ArrowDown', 'Enter', 'Escape']) {
        assert.ok(!page.press(name).defaultPrevented, name);
    }
});

test('no results leave nothing selected and enter does nothing', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const enter = page.press('Enter');

    assert.equal(page.selected(), undefined);
    assert.equal(page.field.getAttribute('aria-activedescendant'), null);
    assert.ok(enter.defaultPrevented);
    assert.ok(page.isOpen());
});

test('clear the search empties the field and asks again instead of leaving the page', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const click = page.click(page.results.buttons[0]);
    await net.answer(2, RECENT);

    assert.ok(click.defaultPrevented);
    assert.equal(page.field.value, '');
    assert.equal(page.activeElement, page.field);
    assert.deepEqual(net.asked(), ['', 'zebra', '']);
    assert.equal(page.results.innerHTML, RECENT);
});

test('enter on the focused clear button is left to the button', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const enter = page.press('Enter', {}, page.results.buttons[0]);

    assert.ok(!enter.defaultPrevented);
});

test('an answer that failed says so in the list', async () => {
    const { page, net } = await openWithRecent();
    page.type('alpha');

    await net.answer(1, 'Bad Request', 400);

    assert.match(page.results.innerHTML, /role="alert".*Search did not answer\./);
    assert.equal(page.results.options.length, 0);
});

test('a network failure says so in the list', async () => {
    const { page, net } = await openWithRecent();
    page.type('alpha');

    await net.fail(1);

    assert.match(page.results.innerHTML, /Search did not answer\./);
});

test('a page turbo keeps for going back is kept with the search closed', async () => {
    const { page } = await openWithRecent();

    page.dispatch('turbo:before-cache', {});

    assert.ok(!page.isOpen());
    assert.ok(!page.shell.inert);
});

test('typing in another field of the page asks nothing', async () => {
    const { page, net } = await openWithRecent();

    page.dispatch('input', { target: page.trigger });

    assert.deepEqual(net.asked(), ['']);
});
