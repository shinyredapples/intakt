const DETAILS = 'details.review-details';
// The width from which the stylesheet shows the timeline and the live log side by side.
const WIDE = '(min-width: 761px)';

// The server renders the review's details closed, as a phone shows them; a wide screen has room for them, so they open on each load. A resize leaves them as the reader set them.
export function openReviewDetails(page, view) {
    const wide = view.matchMedia(WIDE);
    const open = () => {
        if (!wide.matches) {
            return;
        }
        for (const details of page.querySelectorAll(DETAILS)) {
            details.open = true;
        }
    };
    page.addEventListener('turbo:load', open);
    page.addEventListener('turbo:render', open);
    open();
}
