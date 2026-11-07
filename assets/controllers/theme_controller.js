import { Controller } from '@hotwired/stimulus';

// The inline script in templates/dashboard/base.html.twig reads this key before the first paint.
const STORAGE_KEY = 'intakt-theme';

export default class extends Controller {
    flip() {
        const root = document.documentElement;
        const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';

        root.dataset.theme = theme;
        localStorage.setItem(STORAGE_KEY, theme);
    }
}
