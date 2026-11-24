const TOOLBAR = '[data-history-filter]';
const OUT = 'data-filtered-out';

// Not a Stimulus controller, because a History page the hub cannot reach loads no import map. Listens on the page once, so a History page a visit puts in later is covered too, and recounts after the shell's project filter ran, because both decide which rows show.
export function filterHistory(page) {
    page.addEventListener('click', (event) => {
        const chip = event.target?.closest?.(`${TOOLBAR} .fchip`);
        if (chip) {
            press(chip);
            narrow(page);
        }
    });
    page.addEventListener('input', (event) => {
        if (event.target?.closest?.(TOOLBAR)) {
            narrow(page);
        }
    });
    page.addEventListener('intakt:project-shown', () => narrow(page));
}

function press(chip) {
    chip.closest(TOOLBAR).querySelectorAll('.fchip').forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
}

function narrow(page) {
    const toolbar = page.querySelector(TOOLBAR);
    if (!toolbar) {
        return;
    }
    const chosen = toolbar.querySelector('.fchip[aria-pressed="true"]')?.dataset.filter ?? '';
    const text = toolbar.querySelector('input').value.trim().toLowerCase();
    let shown = 0;
    page.querySelectorAll('.ledger tbody tr').forEach((row) => {
        const matches = isChosen(row, chosen) && (text === '' || row.textContent.toLowerCase().includes(text));
        row.toggleAttribute(OUT, !matches);
        if (matches && !row.hidden) {
            shown++;
        }
    });
    page.querySelector('.history-none').hidden = shown > 0;
}

function isChosen(row, chosen) {
    return chosen === '' || row.dataset.source === chosen || row.dataset.topics.split(' ').includes(chosen);
}
