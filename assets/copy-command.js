// Only inside a waiting item, so the Copy button the components page shows as a sample stays without effect.
const COPY = '.wait-item button[data-copy]';
const WHY = '.wait-item details.why';
const PHONE = '(max-width: 760px)';
const SHOWN_FOR_MS = 2000;

// A page bound twice would answer one click twice: two clipboard writes, and the second run would keep "Copied" as the label to put back.
const bound = new WeakSet();

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and the owner still has to paste the command. Listens on the page once, however often it is called.
export function copyCommands(page, clipboard, phone, selection) {
    closeWhyOnPhones(page, phone);
    if (bound.has(page)) {
        return;
    }
    bound.add(page);
    // A Map and not a WeakMap, because Turbo's snapshot needs every button that still shows a message; a button leaves it when its label is back.
    const shown = new Map();
    page.addEventListener('click', async (event) => {
        const button = event.target?.closest?.(COPY);
        if (!button) {
            return;
        }
        const done = await copied(clipboard, button.dataset.copy);
        show(button, done ? 'Copied' : 'Select and copy', shown);
        // Selected on a failure, because a page on plain http has no clipboard and the owner can still copy the selection by hand.
        if (!done) {
            selection?.selectAllChildren(button.previousElementSibling);
        }
    });
    page.addEventListener('keydown', (event) => closeWhyOnEscape(event, phone));
    // A visit or a refresh puts every Why in again as the server rendered it, which is open.
    page.addEventListener('turbo:load', () => closeWhyOnPhones(page, phone));
    page.addEventListener('turbo:render', () => closeWhyOnPhones(page, phone));
    // The timer would reset the button that left the page, not the copy Turbo keeps, so a page restored with Back would keep "Copied".
    page.addEventListener('turbo:before-cache', () => restoreLabels(shown));
}

function restoreLabels(shown) {
    shown.forEach(({ label, timer }, button) => {
        clearTimeout(timer);
        button.textContent = label;
    });
    shown.clear();
}

async function copied(clipboard, text) {
    try {
        await clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}

// The first label is kept apart and a second click restarts the wait, so a quick second click can never leave its own message as the label.
function show(button, message, shown) {
    const before = shown.get(button);
    clearTimeout(before?.timer);
    const label = before?.label ?? button.textContent;
    button.textContent = message;
    shown.set(button, {
        label,
        timer: setTimeout(() => {
            button.textContent = label;
            shown.delete(button);
        }, SHOWN_FOR_MS),
    });
}

// Esc closes the Why the reader opened on a phone and hands focus back to its summary; a desktop shows every Why open (DESIGN.md), so Esc leaves it there.
function closeWhyOnEscape(event, phone) {
    const why = 'Escape' === event.key && phone(PHONE).matches ? event.target?.closest?.(WHY) : null;
    if (why?.open) {
        why.open = false;
        why.querySelector('summary')?.focus();
    }
}

// The server opens every Why, so a desktop shows it at once; a phone closes it behind its summary, which the keyboard still reaches.
function closeWhyOnPhones(page, phone) {
    if (phone(PHONE).matches) {
        page.querySelectorAll(WHY).forEach((why) => why.removeAttribute('open'));
    }
}
