const TOOLBAR = '[data-history-filter]';
const PICKER = `${TOOLBAR} select`;
const SHARED = '#projfilter';
const OUT = 'data-filtered-out';

// Not a Stimulus controller, because a History page the hub cannot reach loads no import map. Listens on the page once, so a History page a visit puts in later is covered too, and recounts after the shell's project filter ran, because both decide which rows show.
// The phone's top bar has no project filter and the shell shows every project there, so on a phone the toolbar's own picker narrows the rows; it hands its choice to the shared filter, which keeps it for the next page and for a wider screen.
export function filterHistory(page, phone) {
    page.addEventListener('click', (event) => {
        const chip = event.target?.closest?.(`${TOOLBAR} .fchip`);
        if (chip) {
            press(chip);
            narrow(page, phone);
        }
    });
    page.addEventListener('input', (event) => {
        // The picker's own input comes before its change, so it is left to the shared filter, which this run would otherwise read before it heard of the choice.
        if (event.target?.closest?.(TOOLBAR) && !event.target.closest(PICKER)) {
            narrow(page, phone);
        }
    });
    page.addEventListener('change', (event) => {
        const picker = event.target?.closest?.(PICKER);
        const shared = page.querySelector(SHARED);
        if (picker && shared) {
            // A live update may have taken the project off the shared filter since the page loaded; a value it does not list would leave it without a selected option, which the shell reads.
            const listed = [...shared.options].some((option) => option.value === picker.value);
            picker.value = listed ? picker.value : '';
            shared.value = picker.value;
            shared.dispatchEvent(new Event('change'));
        }
    });
    page.addEventListener('intakt:project-shown', () => narrow(page, phone));
    // The shell's first run on a full load comes before this module, so its choice is read here once.
    narrow(page, phone);
}

function press(chip) {
    chip.closest(TOOLBAR).querySelectorAll('.fchip').forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
}

function narrow(page, phone) {
    const toolbar = page.querySelector(TOOLBAR);
    if (!toolbar) {
        return;
    }
    const project = pickedProject(page, toolbar, phone);
    const chosen = toolbar.querySelector('.fchip[aria-pressed="true"]')?.dataset.filter ?? '';
    const text = toolbar.querySelector('input').value.trim().toLowerCase();
    let shown = 0;
    page.querySelectorAll('.ledger tbody tr').forEach((row) => {
        const matches = isChosen(row, chosen) && isOfProject(row, project) && (text === '' || row.textContent.toLowerCase().includes(text));
        row.toggleAttribute(OUT, !matches);
        if (matches && !row.hidden) {
            shown++;
        }
    });
    // The status region stays on the page and only its text changes, so a screen reader hears it; the same text is not set again, or every key typed would repeat it.
    const status = page.querySelector('.history-none');
    const message = shown > 0 ? '' : status.dataset.none;
    if (status.textContent !== message) {
        status.textContent = message;
    }
}

// Follows the shared filter, which a stored choice, a project link or a live update may have changed; a project the picker does not list yet reads as All projects, so it never hides rows under a name it cannot show.
function pickedProject(page, toolbar, phone) {
    const picker = toolbar.querySelector('select');
    if (!picker) {
        return '';
    }
    const shared = page.querySelector(SHARED)?.value ?? picker.value;
    picker.value = [...picker.options].some((option) => option.value === shared) ? shared : '';

    return phone.matches ? picker.value : '';
}

// As the shell's filter does, a row of no project shows under every project.
function isOfProject(row, project) {
    return project === '' || row.dataset.project === undefined || row.dataset.project === project;
}

function isChosen(row, chosen) {
    return chosen === '' || row.dataset.source === chosen || row.dataset.topics.split(' ').includes(chosen);
}
