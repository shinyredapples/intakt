<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMovesToAnotherAccount;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;

// Plan T25: a quota that runs out during a round must end it as changes or blocked, because Symfony AI reports a quota as a rate limit without a retry delay; scripted through the real vendor stack so the end and the usage rows are those an install records.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(UsageLedger::class)]
#[CoversClass(AccountCooldownWindows::class)]
#[CoversClass(ReviewChange::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(ReviewerMoves::class)]
#[UsesClass(ReviewerMovesToAnotherAccount::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountOut::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(NoProviderLeft::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(Account::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class QuotaExhaustedMidRoundTest extends KernelTestCase
{
    private ScriptedRound $run;

    protected function setUp(): void
    {
        $this->run = new ScriptedRound(new RoundFixture(self::getContainer()), self::getContainer());
    }

    public function testAQuotaExhaustedAfterTheFirstReviewerAnsweredEndsTheRoundAsChangesWithTheReasonsOfTheOthers(): void
    {
        $review = $this->quotaRunsOutAfterAnAnswer();

        self::assertSame(Decision::Changes, $review->decision);
        self::assertCount(2, $review->reasons);

        foreach ($review->reasons as $reason) {
            self::assertMatchesRegularExpression('/^role-[23] reviewer blocked, Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', $reason);
        }
    }

    public function testAQuotaExhaustedOnTheTierAndItsSubstituteWithNoResetEndsTheRoundBlockedWithTheReasonOfEachReviewer(): void
    {
        $review = $this->quotaOutFromTheFirstCall();

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertCount(3, $review->reasons);

        foreach ($review->reasons as $reason) {
            self::assertMatchesRegularExpression('/^role-\d reviewer blocked, Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', $reason);
        }
    }

    public function testEachBlockedReviewerRecordsItsQuotaReasonInTheLedger(): void
    {
        $this->quotaOutFromTheFirstCall();

        $reasons = $this->run->payloadValues(LedgerEventKind::ReviewerBlocked, 'reason');
        self::assertCount(3, $reasons);

        foreach ($reasons as $reason) {
            self::assertIsString($reason);
            self::assertMatchesRegularExpression('/^Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', $reason);
        }
    }

    public function testTheCallAnsweredBeforeTheQuotaRanOutIsSettledOnce(): void
    {
        $this->quotaRunsOutAfterAnAnswer();

        self::assertSame(['started', 'settled'], array_first($this->run->usagePhasesByCall()));
    }

    public function testEachCallTheQuotaInterruptedIsLeftUnsettledOnceAndNeverSettled(): void
    {
        $this->quotaRunsOutAfterAnAnswer();

        $interrupted = array_slice(array_values($this->run->usagePhasesByCall()), 1);
        self::assertNotSame([], $interrupted);

        foreach ($interrupted as $phases) {
            self::assertSame(['started', 'unsettled'], $phases);
        }
    }

    public function testAnExhaustedAccountCoolsDownOnceThoughLaterCallsOnItWereRefused(): void
    {
        $this->quotaOutFromTheFirstCall();

        self::assertSame(['anthropic.default', 'openai.default'], $this->run->payloadValues(LedgerEventKind::AccountCooledDown, 'account'));
    }

    public function testAnAccountThatRanOutAfterAnAnswerCoolsDownOnce(): void
    {
        $this->quotaRunsOutAfterAnAnswer();

        self::assertSame(['anthropic.default', 'openai.default'], $this->run->payloadValues(LedgerEventKind::AccountCooledDown, 'account'));
    }

    private function quotaRunsOutAfterAnAnswer(): ChangeReview
    {
        $vendor = $this->run->vendor($this->exhaustedAfter($this->run->answering('changes')), $this->exhaustedAfter());

        return $this->run->review(3, $vendor);
    }

    private function quotaOutFromTheFirstCall(): ChangeReview
    {
        return $this->run->review(3, $this->run->vendor($this->exhaustedAfter(), $this->exhaustedAfter()));
    }

    // The n-th call goes to the n-th platform and the quota platform takes the rest, so the order is forced by a call counter and not by time.
    private function exhaustedAfter(PlatformInterface ...$answers): PlatformInterface
    {
        $quota = new FailingWhenRead(new RateLimitExceededException(null, 'Quota exceeded.'));

        return new InFlightTogether($this->run->deliverNext(...), ...[...$answers, $quota]);
    }
}
