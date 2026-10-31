<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundCompletion;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('persistence')]
#[CoversClass(RunReviewRoundHandler::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(RoundCompletion::class)]
#[CoversClass(RoundLedger::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
final class RunReviewRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testARoundDispatchesOneMessagePerReviewerAndWaitsForThem(): void
    {
        $round = $this->fixture->start(3);

        self::assertCount(3, $this->fixture->bus->messages);
        self::assertSame(RoundState::Collecting, $round->state());
        self::assertSame([ReviewerState::Queued, ReviewerState::Queued, ReviewerState::Queued], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $round->reviewers()));
    }

    public function testARoundWhoseMessagesCannotAllBeQueuedLeavesNoRoundAndNoLedgerEventBehind(): void
    {
        try {
            $this->fixture->startOn(new RefusingQueue(), 3);
            self::fail('The refused dispatch was swallowed.');
        } catch (RuntimeException $refused) {
            self::assertSame('queue unavailable', $refused->getMessage());
        }

        self::assertSame([], $this->fixture->storedRounds());
        self::assertSame([], $this->fixture->ledgerKinds());
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function everyOrderOfThree(): iterable
    {
        yield 'first, second, third' => [[0, 1, 2]];
        yield 'third, second, first' => [[2, 1, 0]];
        yield 'second, third, first' => [[1, 2, 0]];
        yield 'first, third, second' => [[0, 2, 1]];
    }

    /**
     * @param list<int> $order
     */
    #[DataProvider('everyOrderOfThree')]
    public function testARoundCompletesWithTheLastReviewerWhicheverFinishesFirst(array $order): void
    {
        $round = $this->fixture->start(3);
        $vendor = new ScriptedVendor();

        foreach ($order as $position => $index) {
            $this->fixture->deliver($this->fixture->bus->messages[$index], $vendor);

            $isLast = 2 === $position;
            self::assertSame($isLast ? RoundState::Complete : RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
        }

        $reviewers = $this->fixture->round($round->id()->toRfc4122())->reviewers();
        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict, ReviewerState::Verdict], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $reviewers));
        self::assertSame(3, $vendor->calls);
    }

    public function testAMessageDeliveredAgainAfterTheRoundIsCompleteChangesNothing(): void
    {
        $round = $this->fixture->start(1);
        $vendor = new ScriptedVendor();
        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
        self::assertSame(1, $vendor->calls);
        self::assertCount(3, $this->fixture->ledgerKinds());
    }

    public function testAFailedReviewerCountsAsFinishedAndTheRoundStillCompletes(): void
    {
        $round = $this->fixture->start(2);
        $failing = new ScriptedVendor(new ProviderFailedTransiently(new RuntimeException('secret detail')));

        $this->fixture->deliver($this->fixture->bus->messages[0], $failing);
        $this->fixture->deliver($this->fixture->bus->messages[1], new ScriptedVendor());

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame(ProviderFailedTransiently::class, $stored->reviewers()[0]->failure());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[1]->state());
    }

    public function testTheLedgerHoldsTheStartAndTheEndOfEveryReviewer(): void
    {
        $this->fixture->start(2);
        $failing = new ScriptedVendor(new ProviderFailedTransiently(new RuntimeException('down')));

        $this->fixture->deliver($this->fixture->bus->messages[1], new ScriptedVendor());
        $this->fixture->deliver($this->fixture->bus->messages[0], $failing);

        self::assertSame(
            [LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerFailed],
            $this->fixture->ledgerKinds(),
        );
    }

    public function testARestartedWorkerSkipsFinishedReviewersAndRunsTheRest(): void
    {
        $round = $this->fixture->start(3);
        $before = new ScriptedVendor();
        $this->fixture->deliver($this->fixture->bus->messages[1], $before);

        // The queue redelivers every message after the restart because it cannot know which ones were handled.
        $after = new ScriptedVendor();
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $after);
        }

        self::assertSame(1, $before->calls);
        self::assertSame(2, $after->calls);
        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAReviewerThatWasRunningWhenTheWorkerStoppedFailsAndTheRoundStillCompletes(): void
    {
        $round = $this->fixture->start(1);
        $reviewer = $this->fixture->reviewer($this->fixture->bus->messages[0]->reviewerId);
        $this->fixture->machine('state_machine.reviewer')->apply($reviewer, ReviewerTransition::Start->value);
        $this->fixture->entities->flush();
        $vendor = new ScriptedVendor();

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(0, $vendor->calls);
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame(RoundState::Complete, $stored->state());
    }

    public function testAMessageForAReviewerThatIsNotStoredIsRefused(): void
    {
        $this->fixture->start(1);

        $this->expectException(ReviewerNotFound::class);

        $this->fixture->deliver(new ReviewWithReviewer('019a2600-0000-7000-8000-000000000000'), new ScriptedVendor());
    }
}
