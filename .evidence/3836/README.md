# History and Trends against the mock (part 1 of #3836)

The keyboard-only walk-through of the same pages (part 3) is `keyboard-walkthrough.md` in this folder.

Files: `<page>-<theme>-<width>-build.png` beside `<page>-<theme>-<width>-mock.png`, for page history and trends, theme light and dark, width 1440, 1920 and 390 (24 images, committed with `git add -f` because `.evidence/` is git-ignored).

Capture rules, the same as `docs/design/mocks/tools/screenshots.js` uses for its mock images: viewport 1440x900 full page, 1920x1080 full page (the generator has no History entry at 1920, so the mock was shot with the same call at the generator's 1920 entry size), 390x844 viewport only (not full page). The build side uses the same viewports and the same `colorScheme`.

The build runs on SQLite with the ledger seeded by appending events through the Ledger service. The History build captures come from the first seed: two owner changes, one red head and four review rounds with two reviewers each, none judged. The Trends build captures come from a second, fuller seed on a fresh database (script below): six judged rounds on four days (Nov 24 to 27), each with two reviewer starts, two verdicts, each reviewer's findings with their severities, a priced usage (`claude-sonnet-5-5`, price table of the sample project) and a judge row with kept, merged, deferred and rejected findings, plus two open rounds on Nov 28 (one with both reviewers started, one with none). Names and counts differ from the mock by data, which is not a defect.

Two more build images, `trends-{light,dark}-390-build-full.png`, are full-page captures at 390x844, added because the viewport-only 390 capture ends at the first chart's legend. In a full-page capture the fixed bottom bar is drawn once, at the first viewport's bottom edge, where it covers the Correctness heading; on a phone it stays at the bottom of the screen.

## What differs from the mock

- Ledger: same parts as the mock (title and subtitle, Ledger/Trends sub-tabs, filter chips, text filter, table with When, Source, What, Change, Until; on 390 the stacked list rows with the chips scrolling sideways). The When column prints the absolute UTC time and What the event kind with its project, where the mock shows "Today 11:02" and a sentence. Both are the shipped page's own wording rules (tests in `tests/Core/Dashboard/UseCase/ShowHistoryControllerTest.php`), so they are the page's content (#3835), not a layout defect.
- Ledger at 390: each stacked row of the build ends in two bare "None" lines (the Change and Until values of a row that has neither) with no label, where the mock's rows carry their labels ("Round 1 → 2", "CI running → held", "Until the image builds") and print "None" once. For the owner's Designer review; the row's content belongs to #3835.
- Trends: the sub-tabs, theme colors and component styles match. The build captures at 1440 and 1920 show, top to bottom: the bench headline; five tiles in one row (Passes 8, To fix 7, One reviewer only 71%, Time to verdict 6 min 30 s, Cost per pass 0.1862 USD); the `.charts` grid with Catches by reviewer (Correctness and Security small charts with their legend), Checked findings (To fix 7, Merged 2, Deferred 3, Rejected 4) and Passes and cost per day (five days of passes, four priced days and one "No data"); and Up next with one round In progress and one Waiting to start. The 390 viewport capture shows the headline, the five tiles in rows of three and the first chart's title and legend; the full-page 390 captures show the three charts in one column and Up next. The mock draws other content: the 7 days/14 days/8 weeks chips, queue, merges, findings per merge, agent share, cycle time and rework charts, as bar and line charts with a sentence under each title and "Show as table" links, and no tiles and no Up next. The shared layout parts match: a two-column chart grid at 1440 and 1920 that becomes one column at 390, small multiples side by side, one hue, hairline grids and axis text. Which charts the page draws is the page's content (#3835).
- Defect found and fixed here: on the populated page at 390 the cost chart's value-axis labels ("0.4000", six characters) started left of the drawing and were cut by the page edge. `templates/dashboard/components/bar_chart.html.twig` now widens the plot's left gutter to the longest value label; test `testEveryValueAxisLabelOfACostChartStartsInsideTheDrawing` (and `testACountChartKeepsItsNarrowGutterBecauseItsAxisLabelsAreShort` for the unchanged side) in `tests/Core/Dashboard/UseCase/BarChartTemplateTest.php`. The Trends build captures were taken with the fix. Measured in the browser at 390, 1440 and 1920: no tile figure overlaps its character image, and the page does not scroll sideways.
- Shell: the rail reads Now, You, History, Projects where the mock reads Board, Waiting on you, Projects, Team, History, Settings. Shell content, outside this part.
- Trends has no visible History title above the sub-tabs, where `docs/design/mocks/trends.html` shows one. The page's h1 is screen-reader only, a deliberate choice of the page (#3835) covered by `testThePageNameStaysAHiddenHeadingAndTheBenchSentenceIsNotTheH1` in `tests/Core/Dashboard/UseCase/ShowTrendsControllerTest.php`; flagged for the owner's Designer review.

## The exact capture script

Run as `MOCKS=docs/design/mocks node shoot.js .evidence/3836 http://localhost:7780` (Playwright with the headless Chromium shell; the script lives outside the repository). It shoots both sides per page, theme and size: 1440x900 full page, 1920x1080 full page (the 1920 mock images come from this same call), 390x844 viewport only.

```js
// usage: node shoot.js <out dir> <build address>
const { chromium } = require('/opt/playwright/node_modules/playwright');
const path = require('path');
const [out, addr] = process.argv.slice(2);
const mocks = path.resolve(process.env.MOCKS);
const sizes = [{ w: 1440, h: 900, full: true }, { w: 1920, h: 1080, full: true }, { w: 390, h: 844, full: false }];
(async () => {
  const browser = await chromium.launch();
  for (const page of ['history', 'trends'])
    for (const theme of ['light', 'dark'])
      for (const s of sizes)
        for (const side of ['build', 'mock']) {
          const ctx = await browser.newContext({ viewport: { width: s.w, height: s.h }, colorScheme: theme });
          const p = await ctx.newPage();
          await p.goto(side === 'mock' ? `file://${mocks}/${page}.html` : `${addr}/${page}`, { waitUntil: 'networkidle' });
          await p.screenshot({ path: `${out}/${page}-${theme}-${s.w}-${side}.png`, fullPage: s.full });
          await ctx.close();
        }
  await browser.close();
})();
```

## The seed of the Trends captures

Run as `php seed.php <repository root> claude-sonnet-5-5` with the install's `DATABASE_URL` and `INTAKT_CONFIG` set (the script lives outside the repository). The full-page 390 captures use the capture script's 390x844 context with `fullPage: true` on `/trends`.

```php
<?php

declare(strict_types=1);

// usage: php seed.php <repository root>
// Appends judged, priced review rounds through the Ledger service of the install the environment names.

use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Kernel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

$root = $argv[1];
require $root.'/vendor/autoload.php';
new Dotenv()->bootEnv($root.'/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();
$clock = new MockClock('2026-11-24 09:00:00', 'UTC');
$ledger = new DoctrineLedger($kernel->getContainer()->get('doctrine'), $clock);
$model = $argv[2];

$add = static function (LedgerEventKind $kind, array $payload, string $at, ?string $subject = null) use ($ledger, $clock): void {
    $clock->modify($at);
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR), $subject));
    if (!$result->succeeded()) {
        throw new RuntimeException('append failed at '.$at);
    }
};
$finding = static fn (string $id, string $severity, int $line, array $merged = []): array => ['id' => $id, 'severity' => $severity, 'text' => 'Finding '.$id.' of the seeded round.', 'merged' => $merged, 'file' => 'src/discount.js', 'line' => $line];

// change, number, start, minutes to verdict, correctness findings, security findings, act, follow-ups, judged findings, tokens
$rounds = [
    ['feat/discount-codes', 1, '2026-11-24 09:00:00', 6, ['F1' => 'P1', 'F2' => 'P3'], ['F3' => 'P1', 'F4' => 'P2'], ['F1', 'F4'], ['F2'], [$finding('F1', 'P1', 12, ['F3']), $finding('F2', 'P3', 40), $finding('F3', 'P1', 12), $finding('F4', 'P2', 30)], [42000, 3100]],
    ['feat/discount-codes', 2, '2026-11-24 14:30:00', 4, ['F1' => 'P2'], [], ['F1'], [], [$finding('F1', 'P2', 18)], [38000, 1900]],
    ['fix/rounding', 1, '2026-11-25 10:15:00', 9, ['F1' => 'P2', 'F2' => 'P3'], ['F3' => 'P3'], ['F1'], ['F3'], [$finding('F1', 'P2', 7), $finding('F2', 'P3', 22), $finding('F3', 'P3', 51)], [51000, 4200]],
    ['feat/export', 1, '2026-11-26 08:40:00', 12, ['F1' => 'P1', 'F2' => 'P2'], ['F3' => 'P2', 'F4' => 'P3', 'F5' => 'P1'], ['F1', 'F3', 'F5'], [], [$finding('F1', 'P1', 5, ['F5']), $finding('F2', 'P2', 60), $finding('F3', 'P2', 33), $finding('F4', 'P3', 71), $finding('F5', 'P1', 5)], [67000, 5600]],
    ['feat/export', 2, '2026-11-27 11:05:00', 5, ['F1' => 'P3'], ['F2' => 'P2'], ['F2'], [], [$finding('F1', 'P3', 14), $finding('F2', 'P2', 48)], [45000, 2500]],
    ['fix/rounding', 2, '2026-11-27 16:20:00', 7, [], ['F1' => 'P3'], [], ['F1'], [$finding('F1', 'P3', 9)], [36000, 1400]],
];

$reviewer = static fn (string $round, string $role): array => ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard'];
$at = static fn (string $start, string $offset): string => new DateTimeImmutable($start, new DateTimeZone('UTC'))->modify($offset)->format('Y-m-d H:i:s');

foreach ($rounds as [$change, $number, $start, $minutes, $correctness, $security, $act, $followUps, $findings, $tokens]) {
    $round = Uuid::v7()->toRfc4122();
    $add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'sample', 'reviewers' => 2, 'change' => $change, 'number' => $number], $start);

    foreach (['correctness' => $correctness, 'security' => $security] as $role => $reported) {
        $add(LedgerEventKind::ReviewerStarted, $reviewer($round, $role), $at($start, '+10 seconds'), $round);
        $add(LedgerEventKind::VerdictReceived, $reviewer($round, $role) + ['decision' => [] === $reported ? 'approve' : 'changes', 'findings' => count($reported)], $at($start, '+'.($minutes - 2).' minutes'));
        $add(LedgerEventKind::ReviewerFindingsRecorded, ['round' => $round, 'reviewer' => $round.'-'.$role, 'findings' => array_keys($reported), 'severities' => $reported], $at($start, '+'.($minutes - 2).' minutes'));
    }

    $call = Uuid::v7()->toRfc4122();
    $add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'sample', 'tier' => 'standard', 'round' => $round, 'model' => $model], $at($start, '+20 seconds'));
    $add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'usages' => [['model' => $model, 'prompt' => $tokens[0], 'completion' => $tokens[1], 'total' => $tokens[0] + $tokens[1]]]], $at($start, '+'.($minutes - 1).' minutes'));
    $add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => $act, 'follow_ups' => $followUps, 'findings' => $findings, 'fallback' => false], $at($start, '+'.$minutes.' minutes'));
}

// Two rounds still open, so Up next lists one in progress and one waiting to start.
$running = Uuid::v7()->toRfc4122();
$add(LedgerEventKind::ReviewStarted, ['round' => $running, 'project' => 'sample', 'reviewers' => 2, 'change' => 'feat/import', 'number' => 1], '2026-11-28 05:20:00');
$add(LedgerEventKind::ReviewerStarted, $reviewer($running, 'correctness'), '2026-11-28 05:20:10', $running);
$add(LedgerEventKind::ReviewerStarted, $reviewer($running, 'security'), '2026-11-28 05:20:10', $running);
$waiting = Uuid::v7()->toRfc4122();
$add(LedgerEventKind::ReviewStarted, ['round' => $waiting, 'project' => 'sample', 'reviewers' => 2, 'change' => 'feat/discount-codes', 'number' => 3], '2026-11-28 05:25:00');

echo "seeded ".(count($rounds) + 2)." rounds\n";
```
