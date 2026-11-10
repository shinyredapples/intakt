// As in the mocks: long enough to read the question and click again, short enough that a stray later click does nothing.
const DISARM_AFTER_MS = 4000;
const DANGER_BUTTON = 'button[data-arm-confirm]';
const ARMED = 'data-armed';

const labels = new WeakMap();
const timers = new WeakMap();

// Not a Stimulus controller, because a page the hub cannot reach loads no import map, and a danger button there must still ask first. Listens on the page once, in the capture phase, so no other listener sees a held-back click and a page Turbo puts in later is covered too.
export function holdBackDangerButtons(page) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(DANGER_BUTTON);
        if (!button) {
            return;
        }
        if (button.hasAttribute(ARMED)) {
            disarm(button);
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        arm(button);
    }, true);
    // A page Turbo keeps for going back comes back unarmed, so no button waits armed without its timer.
    page.addEventListener('turbo:before-cache', () => page.querySelectorAll(`[${ARMED}]`).forEach(disarm));
}

function arm(button) {
    labels.set(button, button.textContent);
    button.setAttribute(ARMED, '');
    button.textContent = button.getAttribute('data-arm-confirm');
    timers.set(button, setTimeout(() => disarm(button), DISARM_AFTER_MS));
}

function disarm(button) {
    clearTimeout(timers.get(button));
    button.removeAttribute(ARMED);
    button.textContent = labels.get(button);
}
