<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\Domain\SplitLines;
use Intakt\Core\Build\Domain\SplitPart;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\RecordedSizing;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Build\UseCase\SizeTask\SmallPoolSplit;
use Intakt\Core\Build\UseCase\SizeTask\SplitPlan;
use Intakt\Core\Build\UseCase\SizeTask\SplitTask;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
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

#[CoversClass(SmallPoolSplit::class)]
#[CoversClass(SizeTask::class)]
#[CoversClass(SizingRequest::class)]
#[UsesClass(ConfiguredAccounts::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(SplitTask::class)]
#[UsesClass(SplitPlan::class)]
#[UsesClass(SplitLines::class)]
#[UsesClass(SplitPart::class)]
#[UsesClass(SizingRecords::class)]
#[UsesClass(SizingOutcome::class)]
#[UsesClass(RecordedSizing::class)]
#[UsesClass(EligibleTasks::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
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
final class SmallPoolSplitTest extends TestCase
{
    private const array PARTS = [
        'Read the cart | done-when: /cart lists the items | verify: bin/check --fast | files: src/Cart.php | depends on: none',
        'Show the total | done-when: /cart shows the total | verify: bin/check --fast | files: src/Total.php | depends on: part 1',
    ];

    /**
     * @var list<SizingRequest>
     */
    private array $asked = [];

    public function testATaskAtTheLowerSplitBoundaryOnAOneAccountPoolIsSplitFromASecondAnswer(): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['only']]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $this->split()))->run('alpha', 7);

        self::assertSame([SizingOutcome::Split, ['Read the cart', 'Show the total']], [$outcome, $fixture->parts->titles()]);
        self::assertSame([false, true], array_map(static fn (SizingRequest $request): bool => $request->partsAsked, $this->asked));
        self::assertSame(LedgerEventKind::TaskSplitPlanned, $fixture->ledger->kinds()[0]);
        self::assertSame(['size' => 'split', 'parts' => self::PARTS], array_intersect_key($fixture->ledger->payloads()[0], ['size' => true, 'parts' => true]));
    }

    public function testTheSameEstimateOnAPoolOfTwoAccountsIsKeptWholeWithoutASecondCall(): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['first', 'second']]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $this->split()))->run('alpha', 7);

        $this->assertKeptWholeAfterOneCall($fixture, $outcome);
    }

    public function testOneAccountAtEachOfTwoProvidersIsNoSmallPool(): void
    {
        $fixture = $this->fixtureWith([Provider::Anthropic->value => ['only'], Provider::OpenAi->value => ['only']]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $this->split()))->run('alpha', 7);

        $this->assertKeptWholeAfterOneCall($fixture, $outcome);
    }

    public function testAPartAtTheLowerSplitBoundaryOnAOneAccountPoolIsKeptWhole(): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['only']]);
        $fixture->issue->rewrittenAs("Part 1 of 2 of #3, split by sizing.\n\n<!-- intakt:part task-sizing-abc 1 -->");

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $this->split()))->run('alpha', 7);

        $this->assertKeptWholeAfterOneCall($fixture, $outcome);
    }

    public function testAnEstimateOneLineBelowTheLowerSplitBoundaryIsKeptWholeOnAOneAccountPool(): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['only']]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES - 1), $this->split()))->run('alpha', 7);

        $this->assertKeptWholeAfterOneCall($fixture, $outcome);
    }

    public function testAPoolWithNoEnabledAccountIsSmall(): void
    {
        $fixture = $this->fixtureWith([]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $this->split()))->run('alpha', 7);

        self::assertSame([SizingOutcome::Split, 2], [$outcome, count($this->asked)]);
    }

    /**
     * @return iterable<string, array{?SizingVerdict}>
     */
    public static function secondAnswersWithoutParts(): iterable
    {
        yield 'an answer below split' => [new SizingVerdict(true, [], SizeTier::Large, 450, 'One change.', [])];
        yield 'an invalid answer' => [null];
        yield 'an answer that says not ready' => [new SizingVerdict(false, [ReadinessGap::SingleOutcome], SizeTier::Large, 450, 'Two changes.', [])];
    }

    #[DataProvider('secondAnswersWithoutParts')]
    public function testATaskWhoseSecondAnswerHasNoPartsIsRecordedReadyAndKeptWholeAndNeverParked(?SizingVerdict $second): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['only']]);

        $outcome = $fixture->sizing($this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), $second))->run('alpha', 7);

        self::assertSame([SizingOutcome::Ready, 2, []], [$outcome, count($this->asked), $fixture->parts->filed]);
        self::assertSame([LedgerEventKind::TaskSized], $fixture->ledger->kinds());
        self::assertSame(['outcome' => 'ready', 'size' => 'large', 'estimated_lines' => SmallPoolSplit::LOWER_SPLIT_LINES], array_intersect_key($fixture->ledger->payloads()[0], ['outcome' => true, 'size' => true, 'estimated_lines' => true]));
        self::assertSame(0, $fixture->parked->fetchOne(sprintf('SELECT COUNT(*) FROM %s', ParkedTaskRecord::TABLE)));
    }

    public function testATaskKeptWholeAfterItsSecondAnswerIsNotAskedAgainOnTheSameText(): void
    {
        $fixture = $this->fixtureWith(['anthropic' => ['only']]);
        $vendor = $this->vendor($this->large(SmallPoolSplit::LOWER_SPLIT_LINES), null);
        (void) $fixture->sizing($vendor)->run('alpha', 7);

        $outcome = $fixture->sizing($vendor)->run('alpha', 7);

        self::assertSame([SizingOutcome::AlreadySized, 2], [$outcome, count($this->asked)]);
    }

    private function assertKeptWholeAfterOneCall(SizingFixture $fixture, SizingOutcome $outcome): void
    {
        self::assertSame([SizingOutcome::Ready, 1, []], [$outcome, count($this->asked), $fixture->parts->filed]);
        self::assertSame([LedgerEventKind::TaskSized], $fixture->ledger->kinds());
    }

    /**
     * @param array<string, list<string>> $accounts
     */
    private function fixtureWith(array $accounts): SizingFixture
    {
        return new SizingFixture(self::createStub(ManagerRegistry::class), new InMemoryLedger(), accounts: new ConfiguredAccounts($accounts));
    }

    private function large(int $lines): SizingVerdict
    {
        return new SizingVerdict(true, [], SizeTier::Large, $lines, 'One large change.', []);
    }

    private function split(): SizingVerdict
    {
        return new SizingVerdict(true, [], SizeTier::Split, 450, 'Two changes.', self::PARTS);
    }

    private function vendor(SizingVerdict $first, ?SizingVerdict $second): SizingVendor
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturnCallback(function (SizingRequest $request) use ($first, $second): ?SizingVerdict {
            $this->asked[] = $request;

            return 1 === count($this->asked) ? $first : $second;
        });

        return $vendor;
    }
}
