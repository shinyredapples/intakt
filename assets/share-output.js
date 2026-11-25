const SLIDER = 'input[type="range"][data-share-output]';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map, and its slider must still say its share. Listens on the page once, so a slider a visit puts in later is covered too.
export function followShares(page) {
    page.addEventListener('input', (event) => {
        const slider = event.target?.closest?.(SLIDER);
        const output = slider ? page.getElementById(slider.dataset.shareOutput) : null;
        if (output) {
            output.textContent = `${slider.value}%`;
        }
    });
}
