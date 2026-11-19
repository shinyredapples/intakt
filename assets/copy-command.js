// Long enough to read the message, short enough that the button is ready for the next command.
const RESET_AFTER_MS = 2000;
const COPY_BUTTON = 'button[data-copy]';
const MESSAGE = 'data-copy-message';
const COPIED = 'Copied';
const FAILED = 'Copy failed';
const WHY = 'details.why';
// A wide screen shows every Why, so only a phone has one to close.
const PHONE = '(max-width: 760px)';

const labels = new WeakMap();
const timers = new WeakMap();

// Not a Stimulus controller, because a page the hub cannot reach loads no import map, and its commands must still copy. Listens on the page once, so a command a visit puts in later is covered too.
export function copyCommands(page, view) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(COPY_BUTTON);
        if (button) {
            copy(button, view);
        }
    });
    // A page Turbo keeps for going back must come back saying Copy, with no timer left to change it.
    page.addEventListener('turbo:before-cache', () => page.querySelectorAll(`[${MESSAGE}]`).forEach((button) => restore(button, view)));
    page.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && view.matchMedia(PHONE).matches) {
            closeWhy(event.target?.closest?.(WHY));
        }
    });
}

async function copy(button, view) {
    // Read only while the button shows its own label, because a click during a message would keep the message as the label.
    if (!button.hasAttribute(MESSAGE)) {
        labels.set(button, button.textContent);
    }
    try {
        // A browser without the clipboard (an address that is not localhost or HTTPS) throws here, and is told so like a refusal.
        await view.navigator.clipboard.writeText(button.dataset.copy);
        show(button, COPIED, view);
    } catch {
        show(button, FAILED, view);
    }
}

function show(button, message, view) {
    view.clearTimeout(timers.get(button));
    button.setAttribute(MESSAGE, '');
    button.textContent = message;
    timers.set(button, view.setTimeout(() => restore(button, view), RESET_AFTER_MS));
}

function restore(button, view) {
    view.clearTimeout(timers.get(button));
    timers.delete(button);
    button.removeAttribute(MESSAGE);
    button.textContent = labels.get(button);
}

function closeWhy(why) {
    if (!why?.open) {
        return;
    }
    why.open = false;
    why.querySelector('summary').focus();
}
