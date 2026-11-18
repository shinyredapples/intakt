const COPY = 'button[data-copy]';
const PHONE = '(max-width: 760px)';
const DONE_FOR_MS = 2000;

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and the owner still has to paste the command.
export function copyCommands(page, clipboard, phone) {
    page.addEventListener('click', async (event) => {
        const button = event.target?.closest?.(COPY);
        if (!button) {
            return;
        }
        await clipboard.writeText(button.dataset.copy);
        const label = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = label; }, DONE_FOR_MS);
    });
    closeWhyOnPhones(page, phone);
}

// The server opens every Why, so a desktop shows it at once; a phone closes it behind its summary, which the keyboard still reaches.
function closeWhyOnPhones(page, phone) {
    if (phone(PHONE).matches) {
        page.querySelectorAll('.wait-item .why').forEach((why) => why.removeAttribute('open'));
    }
}
