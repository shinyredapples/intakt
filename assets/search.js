const OVERLAY = '#search';
const FIELD = '#search-q';
const RESULTS = '#search-results';
const OPTION = '[role="option"]';
// The options are reached with the arrows, so only the other controls of the list, such as Clear the search, are Tab stops.
const STOP = 'button:not([tabindex="-1"]), a[href]:not([tabindex="-1"])';
const ACTION = '[data-search-action]';
const CANCEL = '[data-search-cancel]';
const RESULT_LINK = '#search-results a[href]';
const RESULT_OPTION = '#search-results [role="option"]';
// Only the id's own text, so the rest of its line, the label and the space beside it, still opens the review and the whole row stays one target.
const ROUND_ID = '#search-results .round-id code';
const FAILED = '<div class="test-result err" role="alert"><p><strong>Search did not answer.</strong> Type again to try once more; nothing was changed.</p></div>';

// One entry per case of Dashboard\Domain\SearchAction; SearchOverlayTest checks that every case has one.
const ACTIONS = {
    'switch-theme': (search) => {
        search.close();
        search.page.querySelector('[data-theme-toggle]').click();
    },
    'clear-search': (search) => search.clear(),
};

// Not a Stimulus controller, because a page the hub cannot reach loads no import map, and its search must still open. Listens on the page once, which outlives the body Turbo replaces on a visit, and looks the overlay up on every event, so the overlay of a page Turbo puts in later is used.
export function offerSearch(page) {
    const search = new Search(page);
    page.addEventListener('keydown', (event) => search.key(event));
    page.addEventListener('mousedown', (event) => search.press(event));
    page.addEventListener('click', (event) => search.click(event));
    page.addEventListener('input', (event) => search.input(event));
    // A page Turbo keeps for going back comes back with the search closed and the shell usable.
    page.addEventListener('turbo:before-cache', () => search.close());
}

class Search {
    asked = 0;
    answered = 0;
    selected = 0;
    opener = null;

    constructor(page) {
        this.page = page;
    }

    key(event) {
        // An input method composing text (Japanese, Chinese, Korean input) takes Enter and the arrows for its candidates; Safari reports the committing key as key code 229.
        if (event.isComposing || event.keyCode === 229) {
            return;
        }
        if ((event.metaKey || event.ctrlKey) && event.key?.toLowerCase() === 'k') {
            this.toggle(event);
            return;
        }
        if (!this.isOpen()) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }
        if (event.key === 'Tab') {
            this.keepFocus(event);
            return;
        }
        // Only in the field, so Enter on the Clear button presses the button.
        if (event.target !== this.field()) {
            return;
        }
        const moves = { ArrowDown: () => this.move(1), ArrowUp: () => this.move(-1), Enter: () => this.choose() };
        if (Object.hasOwn(moves, event.key)) {
            event.preventDefault();
            moves[event.key]();
        }
    }

    click(event) {
        const target = event.target;
        if (target?.closest?.('.search-trigger')) {
            this.open();
            return;
        }
        if (!this.isOpen()) {
            return;
        }
        if (target === this.overlay()) {
            this.close();
            return;
        }
        if (target?.closest?.(CANCEL)) {
            this.close();
            return;
        }
        const action = target?.closest?.(ACTION);
        if (action && Object.hasOwn(ACTIONS, action.dataset.searchAction)) {
            event.preventDefault();
            ACTIONS[action.dataset.searchAction](this);
            return;
        }
        // The round id is there to be selected and copied, so a click on it keeps the search open where it is.
        if (target?.closest?.(ROUND_ID)) {
            event.preventDefault();
            return;
        }
        // The link opens its page as it is; the overlay only gets out of the way and gives the shell back.
        if (target?.closest?.(RESULT_LINK)) {
            this.close();
        }
    }

    // An option has tabindex -1, so a press would take the focus from the field, where the arrows and Enter act; the click still follows. A press on the round id is the browser's, because holding it back would also stop the selection that copies the id.
    press(event) {
        if (this.isOpen() && event.target?.closest?.(RESULT_OPTION) && !event.target.closest(ROUND_ID)) {
            event.preventDefault();
        }
    }

    input(event) {
        if (this.isOpen() && event.target === this.field()) {
            this.ask();
        }
    }

    toggle(event) {
        if (!this.overlay()) {
            return;
        }
        event.preventDefault();
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        if (!this.overlay() || this.isOpen()) {
            return;
        }
        this.opener = this.page.activeElement;
        this.overlay().hidden = false;
        this.shellInert(true);
        this.clear();
    }

    close() {
        if (!this.isOpen()) {
            return;
        }
        this.overlay().hidden = true;
        this.shellInert(false);
        // An answer still on its way belongs to a search that is over.
        this.asked++;
        this.opener?.focus?.();
        this.opener = null;
    }

    clear() {
        this.field().value = '';
        this.field().focus();
        this.ask();
    }

    async ask() {
        const asked = ++this.asked;
        const results = this.results();
        results.setAttribute('aria-busy', 'true');
        // The results of the words before stay in sight until the answer comes, but Enter must not open one of them.
        this.show(-1);
        const answer = await this.fetchAnswer();
        // Only the newest question is shown, so a slow answer to an older one never replaces a newer one.
        if (asked !== this.asked) {
            return;
        }
        results.removeAttribute('aria-busy');
        results.innerHTML = answer ?? FAILED;
        this.answered = asked;
        this.show(0);
    }

    async fetchAnswer() {
        const address = new URL(this.overlay().dataset.searchUrl, this.page.baseURI);
        address.searchParams.set('q', this.field().value);
        try {
            const response = await fetch(address, { headers: { Accept: 'text/html' } });

            return response.ok ? await response.text() : null;
        } catch {
            return null;
        }
    }

    show(index) {
        const options = this.options();
        options.forEach((option, number) => {
            option.id = `search-option-${number}`;
            option.setAttribute('aria-selected', String(number === index));
        });
        const chosen = options[index];
        if (!chosen) {
            this.field().removeAttribute('aria-activedescendant');
            return;
        }
        this.selected = index;
        this.field().setAttribute('aria-activedescendant', chosen.id);
        chosen.scrollIntoView({ block: 'nearest' });
    }

    move(step) {
        const count = this.options().length;
        if (this.isAnswered() && count > 0) {
            this.show((this.selected + step + count) % count);
        }
    }

    choose() {
        if (this.isAnswered()) {
            this.options()[this.selected]?.click();
        }
    }

    isAnswered() {
        return this.answered === this.asked;
    }

    // The shell is inert, so without this Tab from the last stop of the dialog would leave the page for the browser's own controls, where Escape no longer reaches the search.
    keepFocus(event) {
        // Cancel only exists on a phone: on a wider screen it is hidden, and Tab must not stop on what cannot be seen.
        const cancel = this.page.querySelector(CANCEL);
        const shown = cancel && cancel.getClientRects().length > 0 ? [cancel] : [];
        const stops = [this.field(), ...shown, ...this.results().querySelectorAll(STOP)];
        const [first, last] = [stops[0], stops.at(-1)];
        const leaving = event.shiftKey ? event.target === first : event.target === last;
        if (leaving || !stops.includes(event.target)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        }
    }

    // Everything behind the overlay leaves the reach of the keyboard and of a screen reader while it is open.
    shellInert(inert) {
        const shell = this.page.querySelector('.shell');
        if (shell) {
            shell.inert = inert;
        }
    }

    isOpen() {
        const overlay = this.overlay();

        return overlay !== null && !overlay.hidden;
    }

    options() {
        return [...this.results().querySelectorAll(OPTION)];
    }

    overlay() {
        return this.page.querySelector(OVERLAY);
    }

    field() {
        return this.page.querySelector(FIELD);
    }

    results() {
        return this.page.querySelector(RESULTS);
    }
}
