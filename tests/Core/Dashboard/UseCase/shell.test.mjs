import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Browser } from './shell-page.mjs';

test('a first visit shows the rail open', () => {
    const page = new Browser('light').open();

    assert.equal(page.rail(), undefined);
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'true');
    assert.equal(page.railToggle.title, 'Collapse menu');
});

test('collapsing the rail marks it collapsed and offers to expand it', () => {
    const page = new Browser('light').open();

    page.toggleRail();

    assert.equal(page.rail(), 'collapsed');
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(page.railToggle.title, 'Expand menu');
});

test('a collapsed rail opens again', () => {
    const page = new Browser('light').open();

    page.toggleRail();
    page.toggleRail();

    assert.equal(page.rail(), undefined);
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'true');
});

test('a collapsed rail stays collapsed after a reload', () => {
    const browser = new Browser('light');
    browser.open().toggleRail();

    const page = browser.open();

    assert.equal(page.rail(), 'collapsed');
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'false');
});

test('a reopened rail stays open after a reload', () => {
    const browser = new Browser('light');
    const page = browser.open();
    page.toggleRail();
    page.toggleRail();

    assert.equal(browser.storage.get('intakt-rail'), 'open');
    assert.equal(browser.open().rail(), undefined);
});

test('an unknown stored rail value shows the rail open', () => {
    const browser = new Browser('light');
    browser.storage.set('intakt-rail', 'sideways');

    assert.equal(browser.open().rail(), undefined);
});

test('the rail still collapses when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true }).open();

    page.toggleRail();

    assert.equal(page.rail(), 'collapsed');
});

// The search script answers it (search.test.mjs), so the shell script must not take the key first.
test('ctrl k is left to the search script', () => {
    const page = new Browser('light').open();

    const key = page.press('k', { ctrlKey: true });

    assert.ok(!key.defaultPrevented);
});

test('a mac shows the command key in the search hint', () => {
    assert.equal(new Browser('light', { platform: 'MacIntel' }).open().shortcutHint(), '⌘K');
});

test('other systems keep the ctrl hint', () => {
    assert.equal(new Browser('light').open().shortcutHint(), 'Ctrl K');
});

test('a first visit shows every project', () => {
    const page = new Browser('light').open();

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('choosing a project hides the rows of every other project', () => {
    const page = new Browser('light').open();

    page.chooseProject('beta');

    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('choosing all projects shows every row again', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');

    page.chooseProject('');

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('choosing a project with no rows shows the empty line', () => {
    const page = new Browser('light', { projects: ['alpha', 'beta', 'gamma'] }).open();

    page.chooseProject('gamma');

    assert.deepEqual(page.shownRows(), []);
    assert.equal(page.emptyLineShown(), true);
});

test('choosing a project with rows keeps the empty line hidden', () => {
    const page = new Browser('light', { projects: ['alpha', 'beta', 'gamma'] }).open();

    page.chooseProject('alpha');

    assert.equal(page.emptyLineShown(), false);
});

test('choosing all projects hides the empty line again', () => {
    const page = new Browser('light', { projects: ['alpha', 'beta', 'gamma'] }).open();
    page.chooseProject('gamma');

    page.chooseProject('');

    assert.equal(page.emptyLineShown(), false);
});

test('a phone shows no empty line, because it shows every project', () => {
    const page = new Browser('light', { projects: ['alpha', 'beta', 'gamma'], phone: true }).open();

    page.chooseProject('gamma');

    assert.equal(page.emptyLineShown(), false);
});

test('a stored project with no rows shows the empty line after a reload', () => {
    const browser = new Browser('light', { projects: ['alpha', 'beta', 'gamma'] });
    browser.open().chooseProject('gamma');

    assert.equal(browser.open().emptyLineShown(), true);
});

test('the chosen project stays chosen after a reload', () => {
    const browser = new Browser('light');
    browser.open().chooseProject('alpha');

    const page = browser.open();

    assert.equal(browser.storage.get('intakt-project'), 'alpha');
    assert.equal(page.projectFilter.value, 'alpha');
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a stored project the install no longer lists shows every project', () => {
    const browser = new Browser('light', { projects: ['alpha'], rows: ['alpha'] });
    browser.storage.set('intakt-project', 'gone');

    const page = browser.open();

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a project named all is filtered like any other project', () => {
    const page = new Browser('light', { projects: ['all', 'beta'], rows: ['all', 'beta'] }).open();

    page.chooseProject('all');

    assert.deepEqual(page.shownRows(), ['all']);
});

test('the project filter names the chosen project in its tooltip', () => {
    const page = new Browser('light', { projects: ['a-project-with-a-name-too-long-for-the-top-bar'], rows: [] }).open();

    assert.equal(page.projectFilter.title, 'All projects');
    page.chooseProject('a-project-with-a-name-too-long-for-the-top-bar');

    assert.equal(page.projectFilter.title, 'a-project-with-a-name-too-long-for-the-top-bar');
});

test('a project link chooses its project over the stored one', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=beta' });
    browser.storage.set('intakt-project', 'alpha');

    const page = browser.open();

    assert.equal(page.projectFilter.value, 'beta');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
    assert.equal(browser.storage.get('intakt-project'), 'beta');
});

test('another project can be chosen after following a project link', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('beta');

    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('all projects can be chosen after following a project link', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('');

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('choosing a project drops the project link from the address, so a reload keeps the choice', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=alpha&page=2' });
    const page = browser.open();

    page.chooseProject('beta');

    assert.equal(browser.address, 'http://localhost/reviews?page=2');
    assert.deepEqual(page.history.state, { turbo: { restorationIdentifier: 'visit' } });
    assert.equal(browser.open().projectFilter.value, 'beta');
});

test('choosing a project leaves an address without a project link alone', () => {
    const browser = new Browser('light');
    const page = browser.open();
    page.history.replaceState = () => assert.fail('the address was replaced');

    page.chooseProject('beta');

    assert.equal(browser.address, 'http://localhost/reviews');
});

test('a project link keeps its breadcrumb and the Projects mark while its project is chosen', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    assert.equal(page.breadcrumbShown(), true);
    assert.equal(page.projectsMarked(), true);
});

test('choosing another project after a project link removes its breadcrumb and the Projects mark', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('beta');

    assert.equal(page.breadcrumbShown(), false);
    assert.equal(page.projectsMarked(), false);
});

test('choosing all projects after a project link removes its breadcrumb and the Projects mark', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('');

    assert.equal(page.breadcrumbShown(), false);
    assert.equal(page.projectsMarked(), false);
});

test('a pushed project list without the linked project removes its breadcrumb and the Projects mark', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.pushStream('projfilter', ['beta']);

    assert.equal(page.breadcrumbShown(), false);
    assert.equal(page.projectsMarked(), false);
});

test('a phone keeps the breadcrumb of a project link, because its top bar has no project filter', () => {
    const page = new Browser('light', { phone: true, address: 'http://localhost/reviews?project=alpha' }).open();

    assert.equal(page.breadcrumbShown(), true);
    assert.equal(page.projectsMarked(), true);
});

test('a project link to a project the install does not list leaves the stored project chosen', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=gone' });
    browser.storage.set('intakt-project', 'alpha');

    const page = browser.open();

    assert.equal(page.projectFilter.value, 'alpha');
});

test('a page that marks two options selected shows, filters and stores one and the same project', () => {
    const browser = new Browser('light', { projects: ['01', '1'], rows: ['01', '1'], address: 'http://localhost/reviews?project=1', linked: ['01', '1'] });

    const page = browser.open();

    assert.equal(page.projectFilter.value, browser.storage.get('intakt-project'));
    assert.deepEqual(page.shownRows(), [page.projectFilter.value]);
    assert.equal(page.projectFilter.title, page.projectFilter.value);
});

test('a project link still chooses its project when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true, address: 'http://localhost/reviews?project=alpha' }).open();

    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('the project filter still filters when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true }).open();

    page.chooseProject('alpha');

    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a phone shows every project, because its top bar has no project filter', () => {
    const browser = new Browser('light', { phone: true });
    browser.storage.set('intakt-project', 'alpha');

    const page = browser.open();

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('a phone shows every project after following a project link', () => {
    const page = new Browser('light', { phone: true, address: 'http://localhost/reviews?project=alpha' }).open();

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('a window that narrows to a phone shows every project again', () => {
    const page = new Browser('light').open();
    page.chooseProject('alpha');

    page.resize(true);

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('a phone that widens to a desktop shows the chosen project only', () => {
    const browser = new Browser('light', { phone: true });
    browser.storage.set('intakt-project', 'alpha');
    const page = browser.open();

    page.resize(false);

    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a page left behind by a visit leaves its rows alone when the width changes', () => {
    const page = new Browser('light').open();
    page.chooseProject('alpha');
    page.leave();

    page.resize(true);

    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('several visits leave one width listener and one stream listener of the shell', () => {
    const page = new Browser('light').open();

    const visited = page.visit().visit().visit();

    assert.deepEqual(visited.listeners(), { streamRender: 1, widthChange: 1 });
});

test('after several visits a window that narrows to a phone and widens again filters the current page', () => {
    const page = new Browser('light').open().visit().visit();
    page.chooseProject('alpha');

    page.resize(true);
    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);

    page.resize(false);
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('after several visits a pushed project list keeps the project the current page chose', () => {
    const page = new Browser('light').open().visit().visit();
    page.chooseProject('beta');

    page.pushStream('projfilter', ['alpha', 'beta', 'gamma']);

    assert.equal(page.projectFilter.value, 'beta');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
    assert.equal(page.projectFilter.title, 'beta');
});

test('the history page keeps a project only its rows name, and its choice, when a pushed list lacks it', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['alpha', 'Beta10', 'beta2'], rows: ['beta2', 'alpha', 'Beta10'], pageProjects: ['beta2'] }).open();
    page.chooseProject('beta2');

    page.pushStream('projfilter', ['alpha', 'Beta10', 'gamma']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'alpha', 'beta2', 'Beta10', 'gamma']);
    assert.equal(page.projectFilter.value, 'beta2');
    assert.deepEqual(page.shownRows(), ['beta2']);
});

test('a kept page project stays marked as the page own, so a second push keeps it too', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['alpha', 'zeta'], rows: ['zeta'], pageProjects: ['zeta'] }).open();
    page.chooseProject('zeta');

    page.pushStream('projfilter', ['alpha']);
    page.pushStream('projfilter', ['alpha', 'beta']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'alpha', 'beta', 'zeta']);
    assert.equal(page.projectFilter.value, 'zeta');
});

test('a kept page project goes back where the server put it, also before a shared name a loose comparison calls equal', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['alpha', 'zeta-02', 'zeta-2'], rows: ['zeta-02'], pageProjects: ['zeta-02'] }).open();

    page.pushStream('projfilter', ['alpha', 'zeta-2']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'alpha', 'zeta-02', 'zeta-2']);
});

test('a kept page project that differs from shared ones only in case goes back between them in byte order', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['ZETA', 'Zeta', 'zeta'], rows: ['Zeta'], pageProjects: ['Zeta'] }).open();

    page.pushStream('projfilter', ['ZETA', 'zeta']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'ZETA', 'Zeta', 'zeta']);
});

test('a kept page project that differs from a shared one only in case goes before it when its bytes do, as on the server', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['Alpha', 'alpha'], rows: ['Alpha'], pageProjects: ['Alpha'] }).open();

    page.pushStream('projfilter', ['alpha']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'Alpha', 'alpha']);
});

test('a kept page project that differs from a shared one only in case goes after it when its bytes do, as on the server', () => {
    const page = new Browser('light', { address: 'http://localhost/history', projects: ['Zeta', 'zeta'], rows: ['zeta'], pageProjects: ['zeta'] }).open();

    page.pushStream('projfilter', ['Zeta']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'Zeta', 'zeta']);
});

test('after several visits a pushed list without the chosen project falls back to all projects', () => {
    const page = new Browser('light').open().visit().visit();
    page.chooseProject('beta');

    page.pushStream('projfilter', ['alpha', 'gamma']);

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('a page without pages under more runs the shell without a more item', () => {
    const page = new Browser('light').open();

    page.toggleRail();

    assert.equal(page.more, null);
    assert.equal(page.rail(), 'collapsed');
    assert.equal(page.press('Escape').defaultPrevented, false);
});

test('more opens its sheet and marks itself expanded', () => {
    const page = new Browser('light', { more: true }).open();

    page.tapMore();

    assert.equal(page.sheetOpen(), true);
    assert.equal(page.more.getAttribute('aria-expanded'), 'true');
});

test('more closes its open sheet again', () => {
    const page = new Browser('light', { more: true }).open();

    page.tapMore();
    page.tapMore();

    assert.equal(page.sheetOpen(), false);
    assert.equal(page.more.getAttribute('aria-expanded'), 'false');
});

test('a tap beside the sheet closes it', () => {
    const page = new Browser('light', { more: true }).open();
    page.tapMore();

    page.tapBackdrop();

    assert.equal(page.sheetOpen(), false);
    assert.equal(page.more.getAttribute('aria-expanded'), 'false');
});

test('a tap on a page in the sheet is left to its link', () => {
    const page = new Browser('light', { more: true }).open();
    page.tapMore();

    page.tapSheetLink();

    assert.equal(page.sheetOpen(), true);
});

test('escape closes the sheet and returns the focus to more', () => {
    const page = new Browser('light', { more: true }).open();
    page.tapMore();

    page.press('Escape');

    assert.equal(page.sheetOpen(), false);
    assert.equal(page.more.getAttribute('aria-expanded'), 'false');
    assert.equal(page.moreHasFocus(), true);
});

test('escape with the sheet closed leaves the focus where it is', () => {
    const page = new Browser('light', { more: true }).open();

    page.press('Escape');

    assert.equal(page.sheetOpen(), false);
    assert.equal(page.moreHasFocus(), false);
});

test('a pushed project list keeps the project this tab chose', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');

    page.pushStream('projfilter', ['alpha', 'beta', 'gamma']);

    assert.equal(page.projectFilter.value, 'beta');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
    assert.equal(page.projectFilter.title, 'beta');
});

test('a pushed project list that no longer names the chosen project falls back to All projects and shows every row', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');

    page.pushStream('projfilter', ['alpha']);

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
    assert.equal(page.projectFilter.title, 'All projects');
});

test('a pushed project list adds the new project as an option without choosing it', () => {
    const page = new Browser('light').open();

    page.pushStream('projfilter', ['alpha', 'beta', 'gamma']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'alpha', 'beta', 'gamma']);
    assert.equal(page.projectFilter.value, '');
});

test('a pushed project list leaves the stored choice untouched', () => {
    const browser = new Browser('light');
    const page = browser.open();
    page.chooseProject('beta');

    page.pushStream('projfilter', ['alpha']);

    assert.equal(browser.storage.get('intakt-project'), 'beta');
});

test('a stream for another target leaves the project filter alone', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');

    page.pushStream('somewhere-else', ['alpha']);

    assert.deepEqual(page.projectFilter.options.map((option) => option.value), ['', 'alpha']);
    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('a pushed project list does nothing to a page Turbo has left', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');
    page.leave();

    page.pushStream('projfilter', ['alpha', 'beta']);

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('the project filter keeps filtering through a dropped connection and a push after it reconnects', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');
    page.pushStream('projfilter', ['alpha', 'beta']);

    // The connection drops: no stream event arrives, only the person's own choices.
    page.chooseProject('alpha');
    assert.deepEqual(page.shownRows(), ['alpha']);

    // It reconnects and the hub pushes the list again: the tab keeps its choice.
    page.pushStream('projfilter', ['alpha', 'beta', 'gamma']);

    assert.equal(page.projectFilter.value, 'alpha');
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('choosing a project tells the page after it has hidden the other rows', () => {
    const page = new Browser('light').open();
    const seen = page.listenForShownProjects();

    page.chooseProject('beta');

    assert.deepEqual(seen, [['beta', 'beta']]);
});

test('a window that narrows to a phone tells the page it shows every row again', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');
    const seen = page.listenForShownProjects();

    page.resize(true);

    assert.deepEqual(seen, [['beta', 'alpha', 'beta']]);
});

test('a visit tells the page once the new rows are filtered', () => {
    const page = new Browser('light').open();
    page.chooseProject('alpha');
    const seen = page.listenForShownProjects();

    page.visit();

    assert.deepEqual(seen, [['alpha']]);
});

test('a pushed column keeps the cards this tab showed with show more', () => {
    const page = new Browser('light').open();
    page.column('col-in-review').setAttribute('data-expanded', '');

    page.pushColumn('col-in-review');

    assert.equal(page.column('col-in-review').hasAttribute('data-expanded'), true);
});

test('a pushed column this tab never expanded stays limited to its first page', () => {
    const page = new Browser('light').open();

    page.pushColumn('col-in-review');

    assert.equal(page.column('col-in-review').hasAttribute('data-expanded'), false);
});

test('a card focused inside a column keeps focus when the column is pushed', () => {
    const page = new Browser('light').open();
    page.column('col-in-review').cards[0].focus();

    page.pushColumn('col-in-review');

    assert.equal(page.focusedLink(), '/reviews/round-1');
    assert.equal(page.column('col-in-review').cards.includes(page.focused()), true);
});

test('a term focused inside a card keeps focus when the column is pushed', () => {
    const page = new Browser('light').open();
    page.column('col-in-review').terms[1].focus();

    page.pushColumn('col-in-review');

    assert.equal(page.focused(), page.column('col-in-review').terms[1]);
});

test('a pushed column leaves focus alone when it was outside the column', () => {
    const page = new Browser('light').open();
    page.railToggle.focus();

    page.pushColumn('col-in-review');

    assert.equal(page.focusedLink(), null);
});

test('a pushed column keeps the fold this tab chose', () => {
    const page = new Browser('light').open();
    page.column('col-done').classList.toggle('collapsed', false);

    page.pushColumn('col-done');

    assert.equal(page.column('col-done').classList.contains('collapsed'), false);
});

test('a Done column pushed open onto a tab that folded it is folded again and its strip says so', () => {
    const page = new Browser('light').open();

    page.pushColumn('col-done', false);

    assert.equal(page.column('col-done').classList.contains('collapsed'), true);
    assert.equal(page.column('col-done').querySelector('.col-fold').getAttribute('aria-expanded'), 'false');
});

test('a Done column pushed folded onto a tab that showed it open stays open and its strip says so', () => {
    const page = new Browser('light').open();
    page.column('col-done').classList.toggle('collapsed', false);

    page.pushColumn('col-done', true);

    assert.equal(page.column('col-done').classList.contains('collapsed'), false);
    assert.equal(page.column('col-done').querySelector('.col-fold').getAttribute('aria-expanded'), 'true');
});

test('a pushed column tells the page once its cards are filtered', () => {
    const page = new Browser('light').open();
    const seen = page.listenForShownProjects();

    page.pushColumn('col-in-review');

    assert.equal(seen.length, 1);
});

test('a pushed Working now hides the cards of the projects the filter hides', () => {
    const page = new Browser('light').open();
    page.chooseProject('alpha');

    page.pushWorkingNow(['alpha', 'beta', 'beta']);

    assert.deepEqual(page.shownWorkingNow(), ['alpha']);
});

test('pushed project cards hide the cards of the projects the filter hides', () => {
    const page = new Browser('light').open();
    page.chooseProject('alpha');

    page.pushProjectCards(['alpha', 'beta', 'gamma']);

    assert.deepEqual(page.shownProjectCards(), ['alpha']);
});

test('a card focused in Working now keeps focus when Working now is pushed', () => {
    const page = new Browser('light').open();
    page.workingNow().cards[1].focus();

    page.pushWorkingNow();

    assert.equal(page.focusedLink(), '/reviews/round-2');
    assert.equal(page.workingNow().cards.includes(page.focused()), true);
});

test('a pushed Working now leaves focus alone when it was outside the section', () => {
    const page = new Browser('light').open();
    page.railToggle.focus();

    page.pushWorkingNow();

    assert.equal(page.focused(), page.railToggle);
});
