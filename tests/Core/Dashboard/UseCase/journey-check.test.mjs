import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

const { JOURNEYS, runJourney, report, exitCode } = createRequire(import.meta.url)('../../../../bin/journey-check');

const ADDRESS = 'http://localhost:7780';

class FakePage {
    constructor() {
        this.shots = [];
        this.timeout = null;
    }

    setDefaultTimeout(milliseconds) {
        this.timeout = milliseconds;
    }

    async screenshot({ path }) {
        this.shots.push(path);
    }
}

class FakeBrowser {
    constructor() {
        this.contexts = [];
    }

    async newContext() {
        const context = { page: new FakePage(), closed: false, newPage: async () => context.page, close: async () => { context.closed = true; } };
        this.contexts.push(context);

        return context;
    }
}

// Answers every Playwright call with a chainable stand-in and records the method names and arguments, so a test can see how a journey looks for its controls.
function recordingPage(calls) {
    const stand = () => new Proxy(function () {}, {
        get: (_, name) => (name === 'then' ? undefined : (...args) => {
            calls.push({ name, args });

            return stand();
        }),
    });

    return stand();
}

function journeyOf(name, steps) {
    return { name, steps: steps.map(([stepName, run]) => ({ name: stepName, run })) };
}

function withFolder(body) {
    const folder = mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'journey-check-'));

    return body(folder).finally(() => rmSync(folder, { recursive: true, force: true }));
}

test('a journey whose steps all succeed is reported as passed', async () => {
    const result = await runJourney(new FakeBrowser(), ADDRESS, journeyOf('smooth', [['one', async () => {}], ['two', async () => {}]]));

    assert.deepEqual(result, { journey: 'smooth', failure: null });
    assert.equal(report([result]), 'smooth: passed\nJourney check: 1 journeys, 1 passed, 0 failed.');
});

test('a step that throws fails its journey, names that step and skips the later steps', async () => {
    const walked = [];
    const journey = journeyOf('broken', [
        ['open the page', async () => walked.push(1)],
        ['press the button', async () => { throw new Error("locator.click: Timeout 15000ms exceeded.\nCall log:\n  - waiting for getByRole('button', { name: 'Play' })"); }],
        ['read the result', async () => walked.push(3)],
    ]);

    await withFolder(async (folder) => {
        const result = await runJourney(new FakeBrowser(), ADDRESS, journey, folder);

        assert.deepEqual(walked, [1]);
        assert.equal(result.failure.number, 2);
        assert.equal(result.failure.step, 'press the button');
        assert.equal(result.failure.error, "locator.click: Timeout 15000ms exceeded. (- waiting for getByRole('button', { name: 'Play' }))");
    });
});

test('a failed step saves a screenshot named after the journey and the step number', async () => {
    const browser = new FakeBrowser();
    const journey = journeyOf('First run, without a key', [['open', async () => {}], ['press', async () => { throw new Error('gone'); }]]);

    await withFolder(async (folder) => {
        const result = await runJourney(browser, ADDRESS, journey, folder);

        assert.deepEqual(browser.contexts[0].page.shots, [join(folder, 'first-run-without-a-key-step-2.png')]);
        assert.match(result.failure.screenshot, /first-run-without-a-key-step-2\.png$/);
    });
});

test('a screenshot the page cannot take still reports the step that broke', async () => {
    const browser = new FakeBrowser();
    browser.newContext = async () => ({ newPage: async () => ({ setDefaultTimeout() {}, screenshot: async () => { throw new Error('crashed'); } }), close: async () => {} });

    await withFolder(async (folder) => {
        const result = await runJourney(browser, ADDRESS, journeyOf('crash', [['open', async () => { throw new Error('Target crashed'); }]]), folder);

        assert.equal(result.failure.screenshot, null);
        assert.match(report([result]), /crash: failed at step 1, open\n {2}Target crashed\n {2}screenshot: none, the page could not be captured/);
    });
});

test('every journey opens a fresh page in its own browser context and closes it', async () => {
    const browser = new FakeBrowser();
    const journey = journeyOf('any', [['open', async () => {}]]);

    await runJourney(browser, ADDRESS, journey);
    await runJourney(browser, ADDRESS, journey);

    assert.equal(browser.contexts.length, 2);
    assert.notEqual(browser.contexts[0].page, browser.contexts[1].page);
    assert.ok(browser.contexts.every((context) => context.closed));
});

test('the exit code is 0 when every journey passed and 1 when one failed', () => {
    const passed = { journey: 'a', failure: null };
    const failed = { journey: 'b', failure: { number: 1, step: 'open', error: 'gone', screenshot: 'var/journey-check/b-step-1.png' } };

    assert.equal(exitCode([passed, passed]), 0);
    assert.equal(exitCode([passed, failed]), 1);
});

test('the report names the failed step, the reason and the screenshot, and counts the journeys', () => {
    const results = [
        { journey: 'a', failure: null },
        { journey: 'b', failure: { number: 3, step: 'the run reaches its verdict', error: 'Timeout', screenshot: 'var/journey-check/b-step-3.png' } },
    ];

    assert.equal(report(results), [
        'a: passed',
        'b: failed at step 3, the run reaches its verdict',
        '  Timeout',
        '  screenshot: var/journey-check/b-step-3.png',
        'Journey check: 2 journeys, 1 passed, 1 failed.',
    ].join('\n'));
});

test('the first journey is the first run without a key, in the five steps a visitor takes', () => {
    assert.equal(JOURNEYS[0].name, 'first run without a key');
    assert.deepEqual(JOURNEYS[0].steps.map((step) => step.name), [
        '/setup shows See a real review',
        'Play the demo run under See a real review leads to /demo',
        'the recording plays onto the dashboard',
        'the run reaches its verdict',
        'the review page shows the findings',
    ]);
});

test('every journey finds its controls by role and visible text, never by a CSS selector', async () => {
    const allowed = new Set(['goto', 'getByRole', 'getByText', 'waitForURL', 'waitFor', 'click', 'first']);

    for (const journey of JOURNEYS) {
        const calls = [];
        const page = recordingPage(calls);
        for (const step of journey.steps) await step.run(page, ADDRESS);

        assert.deepEqual(calls.map((call) => call.name).filter((name) => !allowed.has(name)), [], journey.name);
    }
});

test('the first-run journey presses Play the demo run inside See a real review', async () => {
    const calls = [];
    const page = recordingPage(calls);
    for (const step of JOURNEYS[0].steps) await step.run(page, ADDRESS);

    const roles = calls.filter((call) => call.name === 'getByRole').map((call) => `${call.args[0]} ${call.args[1]?.name ?? ''}`.trim());
    const press = roles.indexOf('button Play the demo run');

    assert.equal(roles[press - 1], 'region See a real review');
    assert.equal(calls[calls.findIndex((call) => call.name === 'getByRole' && call.args[1]?.name === 'Play the demo run') + 1].name, 'click');
    assert.deepEqual(calls[0], { name: 'goto', args: [ADDRESS + '/setup'] });
});
