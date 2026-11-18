const COPY = 'button[data-copy]';
const WHY = '.wait-item details.why';
const PHONE = '(max-width: 760px)';
const SHOWN_FOR_MS = 2000;

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and the owner still has to paste the command.
export function copyCommands(page, clipboard, phone, selection) {
    const shown = new WeakMap();
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
    closeWhyOnPhones(page, phone);
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
