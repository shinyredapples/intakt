// Long enough to read the message, short enough that the button is ready for the next command.
const RESET_AFTER_MS = 2000;
const COPY_BUTTON = 'button[data-copy]';
const MESSAGE = 'data-copy-message';
const COPIED = 'Copied';
const FAILED = 'Copy failed';

const labels = new WeakMap();
const timers = new WeakMap();
const clicks = new WeakMap();

// Not a Stimulus controller, because a page the hub cannot reach loads no import map, and its commands must still copy. Listens on the page once, so a command a visit puts in later is covered too.
export function copyCommands(page, view) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(COPY_BUTTON);
        if (button) {
            copy(button, view);
        }
    });
    // A page Turbo keeps for going back must come back saying Copy, with no timer left and no write still pending that could change it.
    page.addEventListener('turbo:before-cache', () => {
        page.querySelectorAll(COPY_BUTTON).forEach((button) => {
            clicks.delete(button);
            if (button.hasAttribute(MESSAGE)) {
                restore(button, view);
            }
        });
    });
}

async function copy(button, view) {
    // Set before the text changes, so a screen reader announces "Copied" or "Copy failed".
    button.setAttribute('aria-live', 'polite');
    // Read only while the button shows its own label, because a click during a message would keep the message as the label.
    if (button.hasAttribute(MESSAGE)) {
        // The 2 s count from the press, so a press just before the old deadline cannot have its write still pending when Copy comes back early.
        arm(button, view);
    } else {
        labels.set(button, button.textContent);
    }
    const click = {};
    clicks.set(button, click);
    let message = COPIED;
    try {
        // The browser offers the clipboard only in a secure context it allows (HTTPS or a loopback address such as localhost); without it this throws, and is told so like a refusal.
        await view.navigator.clipboard.writeText(button.dataset.copy);
    } catch {
        message = FAILED;
    }
    // Writes can settle out of order, and a cached page must stay as it was stored, so only the latest click not yet forgotten speaks.
    if (clicks.get(button) === click) {
        clicks.delete(button);
        show(button, message, view);
    }
}

function show(button, message, view) {
    button.setAttribute(MESSAGE, '');
    button.textContent = message;
    arm(button, view);
}

function arm(button, view) {
    view.clearTimeout(timers.get(button));
    timers.set(button, view.setTimeout(() => restore(button, view), RESET_AFTER_MS));
}

function restore(button, view) {
    view.clearTimeout(timers.get(button));
    timers.delete(button);
    button.removeAttribute(MESSAGE);
    button.textContent = labels.get(button);
}
