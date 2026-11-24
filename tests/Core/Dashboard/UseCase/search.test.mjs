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
    parent = null;

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

    // Only the selectors the script asks for, of the element itself and then of its parent.
    closest(selector) {
        const matches = {
            '.search-trigger': this.tag === 'trigger',
            '[data-search-action]': this.attributes.has('data-search-action'),
            '[data-search-cancel]': this.attributes.has('data-search-cancel'),
            '#search-results a[href]': this.tag === 'a' && this.attributes.has('href'),
            '#search-results [role="option"]': this.attributes.get('role') === 'option',
            '#search-results .round-id code': this.tag === 'code' && this.parent?.attributes.get('class') === 'round-id',
        };

        return matches[selector] ? this : (this.parent?.closest(selector) ?? null);
    }

    focus() {
        this.page.activeElement = this;
    }

    click() {
        return this.page.click(this);
    }

    // A hidden element has no boxes, as in a browser.
    getClientRects() {
        return this.hidden ? [] : [{}];
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
        const lists = { '[role="option"]': this.options, 'button:not([tabindex="-1"]), a[href]:not([tabindex="-1"])': this.buttons };
        assert.ok(Object.hasOwn(lists, selector), selector);

        return lists[selector];
    }
}

class FakeKey {
    defaultPrevented = false;

    constructor(key, target, modifiers = {}) {
        this.key = key;
        this.target = target;
        this.ctrlKey = modifiers.ctrlKey ?? false;
        this.metaKey = modifiers.metaKey ?? false;
        this.shiftKey = modifiers.shiftKey ?? false;
        this.isComposing = modifiers.isComposing ?? false;
        this.keyCode = modifiers.keyCode ?? 0;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

const group = (...options) => options.map(([label, attributes]) => `<${attributes.includes('href') ? 'a' : 'div'} class="res" role="option" tabindex="-1" aria-selected="false" ${attributes}><span class="lbl">${label}</span></${attributes.includes('href') ? 'a' : 'div'}>`).join('');
const RECENT = group(['sample-change, round 2', 'href="/reviews/round-2"'], ['sample-change, round 1','href="/reviews/round-1"'], ['Switch light or dark theme', 'data-search-action="switch-theme"']);
const ALPHA = group(['alpha', 'href="/reviews?project=alpha"']);
const NONE = '<div class="none"><p><strong>No results for “zebra”</strong></p><form method="get" action="/search"><button class="btn secondary sm" type="submit" data-search-action="clear-search">Clear the search</button></form></div>';

class FakePage {
    listeners = new Map();
    asked = [];
    activeElement = null;
    baseURI = 'http://localhost/reviews';
    themeClicks = 0;

    constructor({ overlay = true, cancelShown = false } = {}) {
        this.trigger = new FakeNode(this, 'trigger');
        this.shell = new FakeNode(this, 'div');
        this.overlay = overlay ? Object.assign(new FakeNode(this, 'div', { 'data-search-url': '/search' }), { hidden: true }) : null;
        this.field = new FakeNode(this, 'input');
        this.results = new FakeResults(this, 'div');
        this.cancel = Object.assign(new FakeNode(this, 'button', { 'data-search-cancel': '' }), { hidden: !cancelShown });
        this.themeToggle = Object.assign(new FakeNode(this, 'button'), { click: () => this.themeClicks++ });
        this.trigger.focus();
    }

    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    querySelector(selector) {
        const elements = { '#search': this.overlay, '.shell': this.shell, '[data-theme-toggle]': this.themeToggle };
        if (this.overlay) {
            Object.assign(elements, { '#search-q': this.field, '#search-results': this.results, '[data-search-cancel]': this.cancel });
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

    // As in a browser, a press the page does not hold back moves the focus to the pressed element, which an option with tabindex -1 takes.
    mousedown(target) {
        let defaultPrevented = false;
        this.dispatch('mousedown', { target, preventDefault: () => (defaultPrevented = true) });
        if (!defaultPrevented) {
            target.focus();
        }

        return { defaultPrevented };
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

test('a keydown without a key and with ctrl is left to the browser', async () => {
    const { page } = await openPage();

    const key = page.press(undefined, { ctrlKey: true });

    assert.ok(!page.isOpen());
    assert.ok(!key.defaultPrevented);
});

test('a keydown without a key while the search is open does nothing', async () => {
    const { page } = await openWithRecent();
    const selected = page.selected();

    const key = page.press(undefined, { metaKey: true });

    assert.ok(page.isOpen());
    assert.equal(page.selected(), selected);
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

test('enter while the answer to new words is on its way opens none of the results before', async () => {
    const { page } = await openWithRecent();
    const opened = [];
    page.results.options.forEach((option) => (option.click = () => opened.push(option)));

    page.type('entirely different words');
    page.press('Enter');

    assert.deepEqual(opened, []);
    assert.ok(page.isOpen());
});

test('enter after opening again opens none of the results of the search before', async () => {
    const { page } = await openWithRecent();
    page.press('Escape');
    const opened = [];
    page.results.options.forEach((option) => (option.click = () => opened.push(option)));

    page.press('k', { ctrlKey: true });
    page.press('Enter');

    assert.deepEqual(opened, []);
});

test('the results before are left unselected and the arrows wait while the new answer is on its way', async () => {
    const { page } = await openWithRecent();

    page.type('alpha');
    page.press('ArrowDown');

    assert.equal(page.selected(), undefined);
    assert.equal(page.field.getAttribute('aria-activedescendant'), null);
});

test('the new answer is selectable once it arrives', async () => {
    const { page, net } = await openWithRecent();
    page.type('alpha');
    await net.answer(1, ALPHA);
    const opened = [];
    page.results.options[0].click = () => opened.push(page.results.options[0]);

    page.press('Enter');

    assert.equal(opened.length, 1);
});

test('tab and shift tab from the field stay in the search when it is the only stop', async () => {
    const { page } = await openWithRecent();

    for (const shiftKey of [false, true]) {
        const tab = page.press('Tab', { shiftKey });

        assert.ok(tab.defaultPrevented, `shift ${shiftKey}`);
        assert.equal(page.activeElement, page.field);
    }
});

test('tab from the field to the clear button is left to the browser', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const tab = page.press('Tab');

    assert.ok(!tab.defaultPrevented);
});

test('tab from the clear button goes back to the field', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const tab = page.press('Tab', {}, page.results.buttons[0]);

    assert.ok(tab.defaultPrevented);
    assert.equal(page.activeElement, page.field);
});

test('shift tab from the field goes round to the clear button', async () => {
    const { page, net } = await openWithRecent();
    page.type('zebra');
    await net.answer(1, NONE);

    const tab = page.press('Tab', { shiftKey: true });

    assert.ok(tab.defaultPrevented);
    assert.equal(page.activeElement, page.results.buttons[0]);
});

test('pressing cancel closes the search, gives the shell back and returns the focus to the button that opened it', async () => {
    const { page } = await openPage({ cancelShown: true });
    page.trigger.click();
    assert.ok(page.isOpen());

    page.cancel.click();

    assert.ok(!page.isOpen());
    assert.equal(page.shell.inert, false);
    assert.equal(page.activeElement, page.trigger);
});

test('an answer that arrives after cancel is not shown', async () => {
    const { page, net } = await openPage({ cancelShown: true });
    page.trigger.click();
    page.cancel.click();

    await net.answer(0, RECENT);

    assert.equal(page.results.options.length, 0);
});

test('tab from the field stops on cancel when it is shown, and from cancel goes on to the clear button', async () => {
    const { page, net } = await openPage({ cancelShown: true });
    page.trigger.click();
    await net.answer(0, NONE);
    page.press('Tab');
    assert.ok(!page.press('Tab', {}, page.field).defaultPrevented);

    const tab = page.press('Tab', {}, page.results.buttons[0]);

    assert.ok(tab.defaultPrevented);
    assert.equal(page.activeElement, page.field);
    assert.ok(page.press('Tab', { shiftKey: true }, page.field).defaultPrevented);
    assert.equal(page.activeElement, page.results.buttons[0]);
});

test('tab from the field to a hidden cancel button never stops on it', async () => {
    const { page } = await openWithRecent();

    page.press('Tab');

    assert.equal(page.activeElement, page.field);
});

test('tab while the search is closed is left to the page', async () => {
    const { page } = await openPage();

    assert.ok(!page.press('Tab').defaultPrevented);
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

test('pressing the mouse on a result keeps the focus in the field, so the arrows still move the selection', async () => {
    const { page } = await openWithRecent();

    const press = page.mousedown(page.results.options[2]);

    assert.ok(press.defaultPrevented);
    assert.equal(page.activeElement, page.field);
    page.press('ArrowDown');
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-1');
});

// The id's code text inside the round id line of a result, as the template renders it.
function roundIdOf(page, option) {
    const line = Object.assign(new FakeNode(page, 'span', { class: 'round-id' }), { parent: option });

    return { line, code: Object.assign(new FakeNode(page, 'code'), { parent: line }) };
}

test('pressing the mouse on the round id of a result is left to the browser, so its text can be selected', async () => {
    const { page } = await openWithRecent();

    const press = page.mousedown(roundIdOf(page, page.results.options[0]).code);

    assert.ok(!press.defaultPrevented);
});

test('clicking the round id of a result neither follows the result nor closes the search', async () => {
    const { page } = await openWithRecent();

    const click = page.click(roundIdOf(page, page.results.options[0]).code);

    assert.ok(click.defaultPrevented);
    assert.ok(page.isOpen());
});

test('clicking the round id line of a result beside the id follows the result and closes the search', async () => {
    const { page } = await openWithRecent();

    const click = page.click(roundIdOf(page, page.results.options[0]).line);

    assert.ok(!click.defaultPrevented);
    assert.ok(!page.isOpen());
});

test('pressing the mouse on the round id line of a result beside the id keeps the focus in the field', async () => {
    const { page } = await openWithRecent();

    const press = page.mousedown(roundIdOf(page, page.results.options[0]).line);

    assert.ok(press.defaultPrevented);
});

test('clicking a result outside its round id follows it and closes the search', async () => {
    const { page } = await openWithRecent();
    const label = Object.assign(new FakeNode(page, 'span', { class: 'lbl' }), { parent: page.results.options[0] });

    const click = page.click(label);

    assert.ok(!click.defaultPrevented);
    assert.ok(!page.isOpen());
});

test('pressing the mouse outside the results is left to the browser', async () => {
    const { page } = await openWithRecent();

    const press = page.mousedown(page.overlay);

    assert.ok(!press.defaultPrevented);
});

test('pressing the mouse on a result while the search is closed is left to the browser', async () => {
    const { page } = await openWithRecent();
    const option = page.results.options[2];
    page.press('Escape');

    const press = page.mousedown(option);

    assert.ok(!page.isOpen());
    assert.ok(!press.defaultPrevented);
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

test('enter that commits text an input method composes opens nothing and is left to the input method', async () => {
    const { page, net } = await openWithRecent();
    page.type('に');
    await net.answer(1, ALPHA);
    page.results.options[0].click = () => assert.fail('the composing Enter opened the selected result');

    const enter = page.press('Enter', { isComposing: true });

    assert.ok(!enter.defaultPrevented);
    assert.ok(page.isOpen());
});

test('an arrow pressed while an input method composes text moves nothing and is left to the input method', async () => {
    const { page } = await openWithRecent();

    const down = page.press('ArrowDown', { isComposing: true });

    assert.ok(!down.defaultPrevented);
    assert.equal(page.selected().getAttribute('href'), '/reviews/round-2');
});

test('a key safari reports as the composing key code 229 is left to the input method', async () => {
    const { page } = await openWithRecent();
    page.results.options[0].click = () => assert.fail('the composing Enter opened the selected result');

    const enter = page.press('Enter', { keyCode: 229 });

    assert.ok(!enter.defaultPrevented);
    assert.ok(page.isOpen());
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
