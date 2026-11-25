<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewerRateLimited::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(VendorAccountBusy::class)]
final class RateLimitedReviewerTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testARateLimitedReviewerIsQueuedAgainAndItsMessageIsRetriedAfterTheProvidersReset(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $limited = new VerdictPerRole(failure: new ProviderRateLimited(30, new RuntimeException('429')));

        try {
            $this->fixture->deliver($message, $limited);
            self::fail('The rate limit was recorded as a failure.');
        } catch (ReviewerRateLimited $retry) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $retry);
            self::assertSame(30_000, $retry->getRetryDelay());
        }

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame(RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());

        $this->fixture->deliver($message, new VerdictPerRole());

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame(RoundState::Complete, $stored->state());
    }

    public function testARateLimitedReviewerIsNoLongerCountedAsStartedInThePublishedProgress(): void
    {
        $round = $this->fixture->start(1);

        try {
            $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(failure: new ProviderRateLimited(30, new RuntimeException('429'))));
            self::fail('The rate limit was recorded as a failure.');
        } catch (ReviewerRateLimited) {
        }

        $stored = $this->fixture->round($round->id()->toRfc4122())->progress();
        $published = array_last($this->fixture->updates->progress);
        self::assertSame(0, $stored->reviewersStarted);
        self::assertTrue($stored->isSameAs($published));
    }

    public function testARateLimitedReviewerStartsANewConversationWhenItsMessageIsRetried(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $limited = new VerdictPerRole(failure: new ProviderRateLimited(30, new RuntimeException('429')));
        $retried = new VerdictPerRole();

        try {
            $this->fixture->deliver($message, $limited);
        } catch (ReviewerRateLimited) {
        }
        $this->fixture->deliver($message, $retried);

        self::assertNotSame($limited->conversations, $retried->conversations);
        self::assertNotContains($message->reviewerId, [...$limited->conversations, ...$retried->conversations]);
    }

    public function testARateLimitWithoutAResetLeavesTheDelayToTheRetryStrategy(): void
    {
        $this->fixture->start(1);

        try {
            $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(failure: new ProviderRateLimited(null, new RuntimeException('429'))));
            self::fail('The rate limit was recorded as a failure.');
        } catch (ReviewerRateLimited $retry) {
            self::assertNull($retry->getRetryDelay());
        }
    }

    public function testAnAccountAtItsParallelLimitQueuesTheReviewerAgainWithoutAFailure(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->deliver($message, new VerdictPerRole(failure: VendorAccountBusy::at('anthropic')));
            self::fail('The busy account was recorded as a failure.');
        } catch (ReviewerRateLimited $retry) {
            self::assertNull($retry->getRetryDelay());
        }

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
    }

    public function testARateLimitDoesNotUseUpTheOneRetryOfAStoppedWorker(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->deliver($message, new VerdictPerRole(failure: new ProviderRateLimited(1, new RuntimeException('429'))));
        } catch (ReviewerRateLimited) {
        }

        $this->fixture->takeAndStop($message);
        $this->fixture->clock->modify('+1 hour');
        $this->fixture->deliver($message, new VerdictPerRole());

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertContains(LedgerEventKind::VerdictReceived, $this->fixture->ledgerKinds());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }
}
