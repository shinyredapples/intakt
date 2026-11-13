const TOGGLE = '.banner-toggle';

// Not a Stimulus controller, because a board the hub cannot reach loads no import map and a phone could never open its banners. Listens on the page once, so a banner a visit puts in later is covered too.
export function toggleBanners(page) {
    page.addEventListener('click', (event) => {
        const toggle = event.target?.closest?.(TOGGLE);
        if (toggle) {
            toggleBanner(toggle);
        }
    });
}

function toggleBanner(toggle) {
    const open = toggle.closest('.banner').classList.toggle('open');

    toggle.setAttribute('aria-expanded', String(open));
}
