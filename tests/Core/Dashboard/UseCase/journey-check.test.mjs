import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../../../../bin/journey-check', import.meta.url));
const { STEP_TIMEOUT_MS, OUTCOME, RECORDED_FINDING, JOURNEYS, parseAddress, expectAnswer, runName, runJourney, screenshotFile, press, report, exitCode } = createRequire(import.meta.url)(script);

// The runner's port, as the maintainer chose for the check's stand-in servers.
const PORT = 7781;

function journeyCheck(...args) {
    return new Promise((resolve) => {
        execFile(process.execPath, [script, ...args], (error, stdout, stderr) => resolve({ code: error?.code ?? 0, stdout, stderr }));
    });
}

async function closedPort() {
    const server = createServer();
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    const { port } = server.address();
    await new Promise((resolve) => server.close(resolve));

    return port;
}

function scratch() {
    return mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'journey-check-'));
}

// Stands in for a browser whose page cannot find the button and cannot take a screenshot either.
function browserWithBrokenPage() {
    const page = {
        setDefaultTimeout() {},
        getByRole: () => ({ click: async () => { throw new Error('locator.click: Timeout 10000ms exceeded.\nCall log: waiting for button'); } }),
        screenshot: async () => { throw new Error('the page is gone'); },
    };

    return { newContext: async () => ({ newPage: async () => page, close: async () => {} }) };
}

test('an address is kept without its user name and password', () => {
    assert.equal(parseAddress('http://someone:hunter2@localhost:7780/'), 'http://localhost:7780');
});

test('an address that is not a URL is refused without echoing its text', () => {
    assert.throws(() => parseAddress('not an address hunter2'), (error) => !error.message.includes('hunter2') && /is not a URL/.test(error.message));
});

test('an address that is not http or https is refused', () => {
    assert.throws(() => parseAddress('ftp://localhost/'), /not an http or https address/);
});

test('a server that accepts the connection and never answers stops the startup within the given time', async () => {
    const server = createServer(() => {});
    await new Promise((resolve) => server.listen(PORT, 'localhost', resolve));
    const started = performance.now();
    try {
        await assert.rejects(expectAnswer(`http://localhost:${PORT}`, 300), new RegExp(`nothing answers at http://localhost:${PORT} within 0.3 s`));
        assert.ok(performance.now() - started < 3000, 'the startup waited past its deadline');
    } finally {
        server.closeAllConnections();
        server.close();
    }
});

test('a server that answers with an error status still counts as answering', async () => {
    const server = createServer((request, response) => response.writeHead(500).end());
    await new Promise((resolve) => server.listen(PORT, 'localhost', resolve));
    try {
        await expectAnswer(`http://localhost:${PORT}`, STEP_TIMEOUT_MS);
    } finally {
        server.closeAllConnections();
        server.close();
    }
});

test('the check stops with exit 2 and names the address without its user info when nothing answers', async () => {
    const port = await closedPort();

    const { code, stdout, stderr } = await journeyCheck(`http://someone:hunter2@localhost:${port}/`);

    assert.equal(code, 2);
    assert.equal(stderr.trim(), `Journey check stopped: nothing answers at http://localhost:${port} within ${STEP_TIMEOUT_MS / 1000} s`);
    assert.ok(!(stdout + stderr).includes('hunter2') && !(stdout + stderr).includes('someone'));
});

test('the check stops with exit 2 without echoing an address that does not parse', async () => {
    const { code, stdout, stderr } = await journeyCheck('http://[hunter2');

    assert.equal(code, 2);
    assert.match(stderr, /the dashboard address is not a URL/);
    assert.ok(!(stdout + stderr).includes('hunter2'));
});

test('the check stops with exit 2 and its usage when no address is given', async () => {
    const { code, stderr } = await journeyCheck();

    assert.equal(code, 2);
    assert.match(stderr, /usage: bin\/journey-check <address of a running dashboard>/);
});

test('a run is named by its start in UTC, safe as a folder name', () => {
    assert.match(runName(new Date(Date.UTC(2026, 10, 24, 9, 5, 7, 12))), /^2026-11-24T09-05-07-012Z-[0-9a-f]{8}$/);
});

test('two runs that start in the same millisecond get different folders', () => {
    const start = new Date(Date.UTC(2026, 10, 24, 9, 5, 7, 12));

    assert.notEqual(runName(start), runName(start));
});

test('a query string or fragment on the address is dropped, so routes append cleanly', () => {
    assert.equal(parseAddress('http://localhost:7780/?t=1#start'), 'http://localhost:7780');
});

test('a failed journey keeps its step and exit 1 when its folder cannot be created', async () => {
    const blocked = join(scratch(), 'a-file');
    writeFileSync(blocked, '');

    const result = await runJourney(browserWithBrokenPage(), 'http://localhost', { name: 'Broken start', steps: [press('button', 'Start')] }, join(blocked, 'run'), 1);

    assert.equal(result.failure.step, 'press the button "Start"');
    assert.equal(result.failure.error, 'locator.click: Timeout 10000ms exceeded.');
    assert.match(result.failure.screenshotError, /ENOTDIR/);
    assert.equal(exitCode([result]), 1);
});

test('a failed journey keeps its step when the page cannot take a screenshot', async () => {
    const result = await runJourney(browserWithBrokenPage(), 'http://localhost', { name: 'Broken start', steps: [press('button', 'Start')] }, scratch(), 1);

    assert.equal(result.failure.step, 'press the button "Start"');
    assert.equal(result.failure.screenshotError, 'the page is gone');
});

test('two journeys whose names reduce to the same file name get different screenshots', () => {
    const first = screenshotFile('/repo/var/journey-check/run', 1, 'Read a verdict');
    const second = screenshotFile('/repo/var/journey-check/run', 2, 'Read a verdict!');

    assert.equal(first, '/repo/var/journey-check/run/01-read-a-verdict.png');
    assert.equal(second, '/repo/var/journey-check/run/02-read-a-verdict.png');
    assert.notEqual(first, second);
});

test('the report names each journey, the step that broke and where its screenshot is', () => {
    const results = [
        { journey: 'Reading a verdict', failure: null },
        { journey: 'First run', failure: { step: 'press the button "Start"', error: 'Timeout', screenshot: '/repo/var/journey-check/run/02-first-run.png' } },
        { journey: 'Setup', failure: { step: 'open /setup', error: 'HTTP 500 for /setup', screenshotError: 'the page is gone' } },
    ];

    assert.equal(report(results, '/repo'), [
        'passed Reading a verdict',
        'failed First run at step "press the button "Start"": Timeout',
        '  screenshot: var/journey-check/run/02-first-run.png',
        'failed Setup at step "open /setup": HTTP 500 for /setup',
        '  no screenshot: the page is gone',
        'Journey check: 3 journeys, 1 passed, 2 failed.',
    ].join('\n'));
});

test('a run whose journeys all passed exits 0', () => {
    assert.equal(exitCode([{ journey: 'First run', failure: null }]), 0);
});

test('the recorded finding is matched by its sentence ending in amount > 100 and its period', () => {
    assert.match('The check changed from amount >= 100 to amount > 100.', RECORDED_FINDING);
    assert.match('The check changed from amount >= 100 to amount > 100. src/discount.js:3', RECORDED_FINDING);
});

test('the recorded finding is not matched by a sentence that goes on to amount > 100.50', () => {
    assert.doesNotMatch('The check changed from amount >= 100 to amount > 100.50.', RECORDED_FINDING);
    assert.doesNotMatch('The check changed from amount >= 100 to amount > 100', RECORDED_FINDING);
});

test('the verdict wait ends on each heading the verdict card can show', () => {
    for (const heading of ['Approved', 'Changes needed', 'Blocked: the reviewers could not give a verdict on this change', 'Failed: every reviewer failed, so nobody checked this change', 'Checked, but the outcome was not recorded']) {
        assert.match(heading, OUTCOME);
    }
});

test('the verdict wait does not end on a heading that only starts like a verdict', () => {
    for (const heading of ['Approved later', 'Blocked:', 'Not approved', 'Review of sample-change in sample']) {
        assert.doesNotMatch(heading, OUTCOME);
    }
});

test('the first journey is First run without a key with its five steps in order', () => {
    assert.deepEqual(JOURNEYS.map((journey) => [journey.name, journey.steps.map((step) => step.name)]), [['First run without a key', [
        'open /setup and see the start',
        'press the button "Play the demo run"',
        'reach /demo and see the demo run',
        'wait for the verdict of the demo run',
        'see the recorded finding on the review page',
    ]]]);
});
