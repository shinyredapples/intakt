const OCTOPUS = 'board-octopus';
const PHONE = '(max-width: 760px)';

// Not a Stimulus controller, for the reason assets/column-fold.js gives. The board renders the asleep octopus hidden with the idle projects while another project works; it shows while the layout's project filter shows one of them. A phone has no filter, so it shows every project and the octopus stays hidden there (#2732).
export function showIdleProjectOctopus(page) {
    page.addEventListener('intakt:project-shown', () => showFor(page));
    // The pushed board replaces the octopus with a hidden one, so the person's project choice is put back on it.
    page.addEventListener('turbo:before-stream-render', (event) => {
        if (event.target.getAttribute('target') !== OCTOPUS) {
            return;
        }
        const render = event.detail.render;
        event.detail.render = (stream) => {
            render(stream);
            showFor(page);
        };
    });
    showFor(page);
}

function showFor(page) {
    const octopus = page.getElementById(OCTOPUS);
    const idle = octopus?.dataset.idleProjects;
    if (idle === undefined) {
        return;
    }
    const filter = page.getElementById('projfilter');
    const chosen = filter !== null && !matchMedia(PHONE).matches ? filter.value : '';
    octopus.hidden = chosen === '' || !JSON.parse(idle).includes(chosen);
}
