<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Closure;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\SplitLines;
use Intakt\Core\Build\Domain\SplitPart;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\RecordedSizing;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Build\UseCase\SizeTask\SplitPlan;
use Intakt\Core\Build\UseCase\SizeTask\SplitTask;
use Intakt\Core\Build\UseCase\SizeTask\TaskNotSized;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitTask::class)]
#[CoversClass(SplitPlan::class)]
#[CoversClass(SizeTask::class)]
#[CoversClass(TaskNotSized::class)]
#[UsesClass(SplitLines::class)]
#[UsesClass(SplitPart::class)]
#[UsesClass(SizingRecords::class)]
#[UsesClass(SizingOutcome::class)]
#[UsesClass(RecordedSizing::class)]
#[UsesClass(EligibleTasks::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(Mentions::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(LabelApplication::class)]
#[UsesClass(KeyedComment::class)]
#[UsesClass(PartIssue::class)]
final class SplitTaskTest extends TestCase
{
    private const array PARTS = [
        'Read the cart | done-when: /cart lists the items | verify: bin/check --fast | files: src/Cart.php | depends on: none',
        'Show the total | done-when: /cart shows the total | verify: bin/check --fast | files: src/Total.php | depends on: part 1',
        'Show the empty cart | done-when: an empty /cart says so | verify: bin/check --fast | files: src/Empty.php | depends on: none',
    ];

    private int $calls = 0;

    public function testAnOversizeTaskIsSplitIntoItsPartsInOrderWithWaitsForLines(): void
    {
        $fixture = $this->fixture();

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame(SizingOutcome::Split, $outcome);
        self::assertSame(['Read the cart', 'Show the total', 'Show the empty cart'], $fixture->parts->titles());
        self::assertSame(
            [
                "Part 1 of 3 of #7, split by sizing.\n\nDone when: /cart lists the items\nVerify: bin/check --fast\nFiles: src/Cart.php",
                "Part 2 of 3 of #7, split by sizing.\n\nDone when: /cart shows the total\nVerify: bin/check --fast\nFiles: src/Total.php\n\nWaits for #100",
                "Part 3 of 3 of #7, split by sizing.\n\nDone when: an empty /cart says so\nVerify: bin/check --fast\nFiles: src/Empty.php",
            ],
            array_column($fixture->parts->filed, 'body'),
        );
    }

    public function testEachPartCarriesItsPartMarkerAndItsOriginalsLabels(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = $fixture->issue->listedAs(SampleIssue::OWNER, SampleIssue::BODY, 'ready', 'priority:high');
        $fixture->issue->history = new IssueHistory(SampleIssue::OWNER, [new LabelApplication('ready', SampleIssue::OWNER)], SampleIssue::BODY, new IssueLabels('ready', 'priority:high'));

        (void) $fixture->sizing($this->vendor())->run('alpha', 7);

        $key = SizingRecords::keyOf('alpha', 7);
        self::assertSame(["<!-- intakt:part {$key} 1 -->", "<!-- intakt:part {$key} 2 -->", "<!-- intakt:part {$key} 3 -->"], array_column($fixture->parts->filed, 'marker'));
        self::assertSame(array_fill(0, 3, ['ready', 'priority:high']), array_column($fixture->parts->filed, 'labels'));
    }

    public function testThePlanIsRecordedBeforeAnyPartExists(): void
    {
        $fixture = $this->fixture();
        $fixture->parts->unreadable = new ForgeReadFailed('GitHub answered 500 to a read of the issues.');

        try {
            (void) $fixture->sizing($this->vendor())->run('alpha', 7);
            self::fail('A split whose parts could not be filed was reported as split.');
        } catch (TaskNotSized $stopped) {
            self::assertSame('The split of issue 7 of project "alpha" stopped; running the command again finishes it from its plan: part 1 was not filed: GitHub answered 500 to a read of the issues.', $stopped->getMessage());
        }

        self::assertSame([LedgerEventKind::TaskSplitPlanned], $fixture->ledger->kinds());
        self::assertSame(['project' => 'alpha', 'task' => '7', 'size' => 'split', 'estimated_lines' => 1200, 'parts' => self::PARTS, 'labels' => ['ready']], array_diff_key($fixture->ledger->payloads()[0], ['text' => true, 'run' => true]));
        self::assertSame([], $fixture->parts->filed);
    }

    public function testTheOriginalStaysOpenAsAnUmbrellaThatNamesItsParts(): void
    {
        $fixture = $this->fixture();

        (void) $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([['alpha', 7, 'This task was split into #100, #101, #102. It stays open as their umbrella.']], $fixture->comments->posted);
        self::assertSame([LedgerEventKind::TaskSplitPlanned, LedgerEventKind::TaskSplit], $this->kindsOfTheSplitIn($fixture));
        self::assertSame(['project' => 'alpha', 'task' => '7', 'parts' => [100, 101, 102]], array_diff_key(array_last($fixture->ledger->payloads()) ?? [], ['run' => true]));
    }

    public function testASplitTaskIsNeverSplitOrSizedAgainAlsoAfterAnEdit(): void
    {
        $fixture = $this->fixture();
        (void) $fixture->sizing($this->vendor())->run('alpha', 7);
        $fixture->issue->rewrittenAs('Users see their items and their total on /cart.');

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::AlreadySized, 1, 3], [$outcome, $this->calls, count($fixture->parts->filed)]);
        self::assertSame([LedgerEventKind::TaskSplitPlanned, LedgerEventKind::TaskSplit], $this->kindsOfTheSplitIn($fixture));
    }

    public function testAPartSizedAsSplitIsKeptWholeAndRecordedAsReady(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->rewrittenAs("Part 1 of 2 of #3, split by sizing.\n\n<!-- intakt:part task-sizing-abc 1 -->");

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::Ready, []], [$outcome, $fixture->parts->filed]);
        self::assertSame([LedgerEventKind::TaskSized], $fixture->ledger->kinds());
        self::assertSame(['ready', 'split'], [$fixture->ledger->payloads()[0]['outcome'] ?? null, $fixture->ledger->payloads()[0]['size'] ?? null]);
    }

    /**
     * @return iterable<string, array{Closure(SizingFixture): void}>
     */
    public static function interruptions(): iterable
    {
        yield 'the plan record is refused' => [static function (SizingFixture $fixture): void { $fixture->splitLedger->refused = LedgerEventKind::TaskSplitPlanned; }];
        yield 'the issues cannot be read' => [static function (SizingFixture $fixture): void { $fixture->parts->unreadable = new ForgeReadFailed('GitHub answered 500 to a read of the issues.'); }];
        yield 'the answer to the first part is lost' => [static function (SizingFixture $fixture): void { $fixture->parts->answerLostFor = 1; }];
        yield 'the answer to the last part is lost' => [static function (SizingFixture $fixture): void { $fixture->parts->answerLostFor = 3; }];
        yield 'the umbrella comment fails' => [static function (SizingFixture $fixture): void { $fixture->comments->failure = new ForgeWriteFailed('GitHub answered 502 to a write.'); }];
        yield 'the split record is refused' => [static function (SizingFixture $fixture): void { $fixture->splitLedger->refused = LedgerEventKind::TaskSplit; }];
    }

    /**
     * @param Closure(SizingFixture): void $interrupt
     */
    #[DataProvider('interruptions')]
    public function testASplitInterruptedAnywhereEndsWithEachPartFiledOnceAndTheFirstPartFree(Closure $interrupt): void
    {
        $fixture = $this->fixture();
        $interrupt($fixture);

        try {
            (void) $fixture->sizing($this->vendor())->run('alpha', 7);
            self::fail('An interrupted split was reported as split.');
        } catch (TaskNotSized) {
        }

        $fixture->splitLedger->refused = null;
        $fixture->parts->unreadable = null;
        $fixture->comments->failure = null;

        self::assertSame(SizingOutcome::Split, $fixture->sizing($this->vendor())->run('alpha', 7));
        self::assertSame(SizingOutcome::AlreadySized, $fixture->sizing($this->vendor())->run('alpha', 7));
        self::assertSame(['Read the cart', 'Show the total', 'Show the empty cart'], $fixture->parts->titles());
        self::assertStringNotContainsString('Waits for', $fixture->parts->filed[0]['body']);
        self::assertStringEndsWith('Waits for #100', $fixture->parts->filed[1]['body']);
        self::assertCount(1, $fixture->comments->posted);
        self::assertSame([LedgerEventKind::TaskSplitPlanned, LedgerEventKind::TaskSplit], $this->kindsOfTheSplitIn($fixture));
    }

    public function testAPlanOnRecordThatCannotBeReadStopsTheSizingWithoutACall(): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, '{"project":"alpha","task":"7","run":"r1","parts":["only one"]}', SizingRecords::keyOf('alpha', 7)));

        try {
            (void) $fixture->sizing($this->vendor())->run('alpha', 7);
            self::fail('A task with an unreadable plan was sized.');
        } catch (TaskNotSized $stopped) {
            self::assertSame('Issue 7 of project "alpha" has a split plan on record that cannot be read, so it is neither split again nor finished.', $stopped->getMessage());
        }

        self::assertSame([0, []], [$this->calls, $fixture->parts->filed]);
    }

    public function testATaskSizedAsSplitBeforeSplittingExistedIsSplitOnTheNextRun(): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append($this->sizedAsSplit(SampleIssue::BODY));

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::Split, 1], [$outcome, $this->calls]);
        self::assertSame(['Read the cart', 'Show the total', 'Show the empty cart'], $fixture->parts->titles());
        self::assertSame([LedgerEventKind::TaskSized, LedgerEventKind::TaskSplitPlanned, LedgerEventKind::TaskSplit], $this->kindsOfTheSplitIn($fixture));
    }

    public function testAPartSizedAsSplitBeforeIsNotSizedAgain(): void
    {
        $fixture = $this->fixture();
        $part = "Part 1 of 2 of #3, split by sizing.\n\n<!-- intakt:part task-sizing-abc 1 -->";
        $fixture->issue->rewrittenAs($part);
        $fixture->ledger->append($this->sizedAsSplit($part));

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::AlreadySized, 0, []], [$outcome, $this->calls, $fixture->parts->filed]);
    }

    public function testATaskSizedAsSplitAndThenSplitIsNotSizedAgain(): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append($this->sizedAsSplit(SampleIssue::BODY));
        (void) $fixture->sizing($this->vendor())->run('alpha', 7);

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::AlreadySized, 1, 3], [$outcome, $this->calls, count($fixture->parts->filed)]);
    }

    public function testAPlanWithOneLabelThatIsNotATextIsUnreadableAndFilesNoPart(): void
    {
        $fixture = $this->fixture();
        $plan = ['project' => 'alpha', 'task' => '7', 'run' => 'r1', 'parts' => self::PARTS, 'labels' => ['ready', 3]];
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, json_encode($plan, JSON_THROW_ON_ERROR), SizingRecords::keyOf('alpha', 7)));

        try {
            (void) $fixture->sizing($this->vendor())->run('alpha', 7);
            self::fail('A plan with a label that is not a text filed its parts.');
        } catch (TaskNotSized $stopped) {
            self::assertSame('Issue 7 of project "alpha" has a split plan on record that cannot be read, so it is neither split again nor finished.', $stopped->getMessage());
        }

        self::assertSame([0, []], [$this->calls, $fixture->parts->filed]);
    }

    public function testAPartWhoseMarkerAnEditRemovedIsKeptWhole(): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplit, '{"project":"alpha","task":"3","run":"r1","parts":[7,8]}', SizingRecords::keyOf('alpha', 3)));

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::Ready, []], [$outcome, $fixture->parts->filed]);
        self::assertSame([LedgerEventKind::TaskSplit, LedgerEventKind::TaskSized], $fixture->ledger->kinds());
    }

    public function testAPartOfAnUnfinishedSplitWhoseMarkerAnEditRemovedIsKeptWhole(): void
    {
        $parent = $this->fixture();
        $filed = $this->firstPartOfASplitStoppedAtItsSecond($parent);
        $part = new SizingFixture(self::createStub(ManagerRegistry::class), $parent->ledger, $filed['number']);
        $part->issue->rewrittenAs(str_replace($filed['marker'], '', $filed['body']));

        $outcome = $part->sizing($this->vendor())->run('alpha', $filed['number']);

        self::assertSame([SizingOutcome::Ready, []], [$outcome, $part->parts->filed]);
    }

    public function testATaskWithTheNumberOfAPartOfAnUnfinishedSplitInAnotherProjectIsSplit(): void
    {
        $parent = $this->fixture();
        $filed = $this->firstPartOfASplitStoppedAtItsSecond($parent);
        $other = new SizingFixture(self::createStub(ManagerRegistry::class), $parent->ledger, $filed['number']);

        $outcome = $other->sizing($this->vendor())->run('widget', $filed['number']);

        self::assertSame([SizingOutcome::Split, 3], [$outcome, count($other->parts->filed)]);
    }

    public function testATaskWithTheNumberOfAPartInAnotherProjectIsSplit(): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplit, '{"project":"beta","task":"3","run":"r1","parts":[7,8]}', SizingRecords::keyOf('beta', 3)));

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::Split, 3], [$outcome, count($fixture->parts->filed)]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function plansOfAnotherShapeOrTask(): iterable
    {
        $plan = ['project' => 'alpha', 'task' => '7', 'run' => 'r1', 'parts' => self::PARTS, 'labels' => ['ready']];

        yield 'labels as an object' => [['labels' => (object) ['ready']] + $plan];
        yield 'parts as an object' => [['parts' => (object) self::PARTS] + $plan];
        yield 'another project' => [['project' => 'beta'] + $plan];
        yield 'another issue' => [['task' => '8'] + $plan];
    }

    /**
     * @param array<string, mixed> $plan
     */
    #[DataProvider('plansOfAnotherShapeOrTask')]
    public function testAPlanOfAnotherShapeOrTaskIsUnreadableAndFilesNoPart(array $plan): void
    {
        $fixture = $this->fixture();
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, json_encode($plan, JSON_THROW_ON_ERROR), SizingRecords::keyOf('alpha', 7)));

        try {
            (void) $fixture->sizing($this->vendor())->run('alpha', 7);
            self::fail('A plan of another shape or task filed its parts.');
        } catch (TaskNotSized $stopped) {
            self::assertStringContainsString('has a split plan on record that cannot be read', $stopped->getMessage());
        }

        self::assertSame([0, []], [$this->calls, $fixture->parts->filed]);
    }

    public function testAPlanWithNoLabelsFilesItsPartsWithNoLabels(): void
    {
        $fixture = $this->fixture();
        $plan = ['project' => 'alpha', 'task' => '7', 'run' => 'r1', 'parts' => self::PARTS, 'labels' => []];
        $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, json_encode($plan, JSON_THROW_ON_ERROR), SizingRecords::keyOf('alpha', 7)));

        $outcome = $fixture->sizing($this->vendor())->run('alpha', 7);

        self::assertSame([SizingOutcome::Split, 0], [$outcome, $this->calls]);
        self::assertSame(array_fill(0, 3, []), array_column($fixture->parts->filed, 'labels'));
    }

    private function sizedAsSplit(string $body): LedgerEvent
    {
        $text = SizingRecords::textOf(new SizingRequest('alpha', 7, 'Add a cart page', $body));
        $payload = ['project' => 'alpha', 'task' => '7', 'outcome' => 'ready', 'missing' => [], 'size' => 'split', 'estimated_lines' => 1200, 'text' => $text, 'run' => 'r0'];

        return new LedgerEvent(LedgerEventKind::TaskSized, json_encode($payload, JSON_THROW_ON_ERROR), SizingRecords::keyOf('alpha', 7));
    }

    /**
     * @return array{number: int, title: string, body: string, marker: string, labels: list<string>}
     */
    private function firstPartOfASplitStoppedAtItsSecond(SizingFixture $parent): array
    {
        $parent->parts->answerLostFor = 2;

        try {
            (void) $parent->sizing($this->vendor())->run('alpha', 7);
            self::fail('A split whose second part was not filed was reported as done.');
        } catch (TaskNotSized $stopped) {
            self::assertStringContainsString('part 2 was not filed', $stopped->getMessage());
        }

        return $parent->parts->filed[0];
    }

    // Without the forge's own lines of each filed part, because the forge's tests cover them and these tests name the split's records.
    /**
     * @return list<LedgerEventKind>
     */
    private function kindsOfTheSplitIn(SizingFixture $fixture): array
    {
        return array_values(array_filter($fixture->ledger->kinds(), static fn (LedgerEventKind $kind): bool => LedgerEventKind::PartIssueCreated !== $kind));
    }

    private function fixture(): SizingFixture
    {
        return new SizingFixture(self::createStub(ManagerRegistry::class), new InMemoryLedger());
    }

    private function vendor(): SizingVendor
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturnCallback(function (): SizingVerdict {
            ++$this->calls;

            return new SizingVerdict(true, [], SizeTier::Split, 1200, 'Three changes.', self::PARTS);
        });

        return $vendor;
    }
}
