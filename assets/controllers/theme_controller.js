import { Controller } from '@hotwired/stimulus';

// Must equal the key the inline script in templates/dashboard/base.html.twig reads, or a flipped theme is lost on reload.
const STORAGE_KEY = 'intakt-theme';

export default class extends Controller {
    flip() {
        const root = document.documentElement;
        const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';

        root.dataset.theme = theme;
        localStorage.setItem(STORAGE_KEY, theme);
    }
}
