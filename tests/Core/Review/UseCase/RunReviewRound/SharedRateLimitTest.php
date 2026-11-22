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
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// Plan T25: the reviewers of one round send their calls together and the provider limits all of them, then the substitute does the same; scripted through the real vendor stack, so the round's end and the usage rows are the ones an install records.
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
final class SharedRateLimitTest extends KernelTestCase
{
    private const int RESET_SECONDS = 300;
    private const string OUT_FOR_THE_RESET = '/^role-\d reviewer blocked, Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/';

    private ScriptedRound $run;

    public function testReviewersLimitedTogetherOnTheTierAndItsSubstituteEndTheRoundBlockedWithTheReason(): void
    {
        $review = $this->everyCallLimited();

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertCount(3, $review->reasons);
        self::assertSame(['role-1', 'role-2', 'role-3'], $this->rolesNamedIn($review));

        foreach ($review->reasons as $reason) {
            self::assertMatchesRegularExpression(self::OUT_FOR_THE_RESET, $reason);
        }
    }

    public function testAnAnsweredCallBesideTwoLimitedTogetherEndsTheRoundAsChangesWithTheReasons(): void
    {
        $review = $this->firstCallAnswered();

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame(['role-2', 'role-3'], $this->rolesNamedIn($review));
    }

    public function testEachBlockedReviewerRecordsItsReasonInTheLedger(): void
    {
        $this->everyCallLimited();

        $reasons = $this->run->payloadValues(LedgerEventKind::ReviewerBlocked, 'reason');
        self::assertCount(3, $reasons);

        foreach ($reasons as $reason) {
            self::assertIsString($reason);
            self::assertMatchesRegularExpression('/^Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', $reason);
        }
    }

    public function testEveryCallWasSentBeforeTheFirstLimitWasRead(): void
    {
        $this->everyCallLimited();

        self::assertSame(['started', 'started', 'started', 'unsettled'], array_slice($this->run->payloadValues(LedgerEventKind::UsageRecorded, 'phase'), 0, 4));
    }

    public function testEveryCallTheLimitInterruptedIsLeftUnsettledOnceAndNeverSettled(): void
    {
        $this->everyCallLimited();

        $phases = $this->run->usagePhasesByCall();
        self::assertCount(6, $phases);
        self::assertSame(array_fill(0, 6, ['started', 'unsettled']), array_values($phases));
    }

    public function testTheAnsweredCallIsSettledOnceBesideTheInterruptedOnes(): void
    {
        $this->firstCallAnswered();

        $phases = array_values($this->run->usagePhasesByCall());
        self::assertSame(['started', 'settled'], $phases[0]);
        self::assertSame(array_fill(0, 4, ['started', 'unsettled']), array_slice($phases, 1));
    }

    public function testEachProviderCoolsDownOnceThoughEveryCallOnItWasLimited(): void
    {
        $this->everyCallLimited();

        self::assertSame(['anthropic.default', 'openai.default'], $this->run->payloadValues(LedgerEventKind::AccountCooledDown, 'account'));
    }

    protected function setUp(): void
    {
        $this->run = new ScriptedRound(new RoundFixture(self::getContainer()), self::getContainer());
    }

    private function everyCallLimited(): ChangeReview
    {
        $vendor = $this->run->vendor($this->run->limitedTogether(self::RESET_SECONDS), $this->run->limitedTogether(self::RESET_SECONDS));

        return $this->run->review(3, $vendor);
    }

    private function firstCallAnswered(): ChangeReview
    {
        $vendor = $this->run->vendor($this->run->answeringThenLimitedTogether('changes', self::RESET_SECONDS), $this->run->limitedTogether(self::RESET_SECONDS));

        return $this->run->review(3, $vendor);
    }

    /**
     * @return list<string>
     */
    private function rolesNamedIn(ChangeReview $review): array
    {
        $roles = array_map(static fn (string $reason): string => explode(' ', $reason)[0], [...$review->reasons]);
        sort($roles);

        return $roles;
    }
}
