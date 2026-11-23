<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryEndpointUnreachable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Review\Contract\RecordedAnswer;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(ReviewerPrompt::class)]
#[CoversClass(ProviderUnreachable::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(EveryEndpointUnreachable::class)]
#[UsesClass(Account::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(FailureInWords::class)]
final class ReviewerRetryVendorTest extends KernelTestCase
{
    private const string NOW = '2026-11-15 12:00:00.000 UTC';

    private InMemoryLedger $ledger;

    /**
     * @var list<bool>
     */
    private array $askedToNameFindings = [];

    public function testAReviewerWhoseEndpointCouldNotBeReachedIsAskedOnceMoreAndThatVerdictCounts(): void
    {
        $verdict = $this->vendor($this->answering($this->unreachable(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1], [$verdict->verdict, count($verdict->findings)]);
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testAReviewerWhoseConfiguredAccountsCouldNotReachTheEndpointIsAskedOnceMoreAndThatVerdictCounts(): void
    {
        $verdict = $this->vendor($this->answering($this->unreachable(...), $this->namedFinding(...)), onAccounts: true)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1], [$verdict->verdict, count($verdict->findings)]);
        self::assertSame([false, false], $this->askedToNameFindings);
    }

    public function testAReviewerWhoseConfiguredAccountsCannotReachTheEndpointTwiceFailsWithThatCause(): void
    {
        $vendor = $this->vendor($this->answering($this->unreachable(...), $this->unreachable(...)), onAccounts: true);

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderUnreachable::class, $failure);
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testTheRetryAfterAnUnreachableEndpointReservesAndCountsItsUsageLikeTheFirstCall(): void
    {
        $this->vendor($this->answering($this->unreachable(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['started', 'unsettled', 'started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(TransportException::class, $this->ledger->payloads()[1]['failure']);
        self::assertNotSame($this->ledger->payloads()[0]['call'], $this->ledger->payloads()[2]['call']);
    }

    public function testTheRetryAfterAnUnreachableEndpointIsNotAskedToNameFindings(): void
    {
        $this->vendor($this->answering($this->unreachable(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request());

        self::assertSame([false, false], $this->askedToNameFindings);
    }

    public function testAReviewerWhoseEndpointCannotBeReachedTwiceFailsWithThatCauseInPlainWords(): void
    {
        $vendor = $this->vendor($this->answering($this->unreachable(...), $this->unreachable(...)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderUnreachable::class, $failure);
        self::assertSame('the model endpoint could not be reached', new FailureInWords()->of($failure::class));
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testAnEndpointUnreachableOnTheRetryAfterTheOutputLimitFailsTheReviewerWithoutAThirdCall(): void
    {
        $vendor = $this->vendor($this->answering(static fn (): never => throw new MaxOutputTokensException('Truncated at the output limit.'), $this->unreachable(...)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderUnreachable::class, $failure);
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testAChangesAnswerWithoutAFindingIsAskedOnceMoreToNameItsFindingsOrApproveAndTheNamedFindingCounts(): void
    {
        $verdict = $this->vendor($this->answering($this->changesWithoutFindings(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1], [$verdict->verdict, count($verdict->findings)]);
        self::assertSame([false, true], $this->askedToNameFindings);
    }

    public function testTheRetryAfterAChangesAnswerWithoutAFindingReservesAndCountsItsUsageLikeTheFirstCall(): void
    {
        $this->vendor($this->answering($this->changesWithoutFindings(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['started', 'settled', 'started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
    }

    public function testASecondChangesAnswerWithoutAFindingFailsTheReviewerWithThatCauseInPlainWords(): void
    {
        $vendor = $this->vendor($this->answering($this->changesWithoutFindings(...), $this->changesWithoutFindings(...)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ChangesWithoutFindings::class, $failure);
        self::assertSame('gave no approval without naming a finding', new FailureInWords()->of($failure::class));
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testARecordedVerdictOnlyAnswerIsAskedAgainWithTheExpectedFindingShapeAndTheNamedFindingCounts(): void
    {
        $verdict = $this->vendor($this->answering($this->recordedVerdictOnly(...), static fn (): TextResult => new TextResult(RecordedAnswer::named(RecordedAnswer::RETRY_WITH_A_FINDING))))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, ['F1']], [$verdict->verdict, array_column($verdict->findings, 'id')]);
        self::assertSame([false, true], $this->askedToNameFindings);
        self::assertStringContainsString(ReviewerPrompt::EXAMPLE_FINDING, ReviewerPrompt::NAME_FINDINGS_OR_APPROVE);
    }

    public function testTheExampleFindingOfTheRequestToNameFindingsIsReadAsAFinding(): void
    {
        $verdict = $this->vendor($this->answering($this->recordedVerdictOnly(...), $this->exampleFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(['F1', 'src/Cart.php', 12], [$verdict->findings[0]->id, $verdict->findings[0]->file, $verdict->findings[0]->line]);
    }

    public function testABlockedAnswerWithoutAFindingIsAskedOnceMoreToNameItsFindingsOrApprove(): void
    {
        $verdict = $this->vendor($this->answering($this->blockedWithoutFindings(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1], [$verdict->verdict, count($verdict->findings)]);
        self::assertSame([false, true], $this->askedToNameFindings);
    }

    public function testASecondBlockedAnswerWithoutAFindingFailsTheReviewerWithTheSameCauseAsAChangesAnswer(): void
    {
        $vendor = $this->vendor($this->answering($this->changesWithoutFindings(...), $this->blockedWithoutFindings(...)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ChangesWithoutFindings::class, $failure);
        self::assertSame('gave no approval without naming a finding', new FailureInWords()->of($failure::class));
    }

    public function testAChangesAnswerNamingAReservedFailureIdIsAskedOnceMoreAndTheNamedFindingCounts(): void
    {
        $verdict = $this->vendor($this->answering($this->reservedFailureId(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1, false], [$verdict->verdict, count($verdict->findings), $verdict->isFailedReview()]);
        self::assertSame([false, false], $this->askedToNameFindings);
    }

    public function testASecondAnswerNamingAReservedFailureIdFailsTheReviewerAsUnreadable(): void
    {
        $vendor = $this->vendor($this->answering($this->reservedFailureId(...), $this->reservedFailureId(...)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(VerdictUnreadable::class, $failure);
        self::assertSame('the reviewer\'s answer could not be read as a verdict: the verdict names finding ids reserved for failed reviews: no-tools.', $failure->keySafeInWords());
        self::assertCount(2, $this->askedToNameFindings);
    }

    public function testAnApprovalWithoutAFindingIsNotAskedAgain(): void
    {
        $verdict = $this->vendor($this->answering($this->approvalWithoutFindings(...), $this->namedFinding(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Approve, $verdict->verdict);
        self::assertSame([false], $this->askedToNameFindings);
    }

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
    }

    private function unreachable(): never
    {
        throw new TransportException('Could not resolve host: localhost');
    }

    private function changesWithoutFindings(): TextResult
    {
        return new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->with('findings', [])->json());
    }

    // In the shape the live log of #2450 showed, because the default correctness model answered a verdict with no finding on both tries of a first review.
    private function recordedVerdictOnly(): TextResult
    {
        return new TextResult(RecordedAnswer::named(RecordedAnswer::VERDICT_ONLY_CHANGES));
    }

    private function blockedWithoutFindings(): TextResult
    {
        return new TextResult(RecordedAnswer::named(RecordedAnswer::VERDICT_ONLY_BLOCKED));
    }

    private function approvalWithoutFindings(): TextResult
    {
        return new TextResult(VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('resolved', [])->with('findings', [])->json());
    }

    private function reservedFailureId(): TextResult
    {
        return new TextResult(VerdictDocument::fromTheDesign()->with('verdict', 'changes')->with('resolved', [])->withFinding('id', Verdict::NO_TOOLS)->json());
    }

    private function exampleFinding(): TextResult
    {
        return new TextResult('{"verdict":"changes","findings":['.ReviewerPrompt::EXAMPLE_FINDING.'],"resolved":[],"follow_ups":[]}');
    }

    private function namedFinding(): TextResult
    {
        return new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json());
    }

    /**
     * @param Closure(): ResultInterface $first
     * @param Closure(): ResultInterface $second
     */
    private function answering(Closure $first, Closure $second): Closure
    {
        return function (Model $model, array|string|object $input) use ($first, $second): ResultInterface {
            $this->askedToNameFindings[] = $this->asksToNameFindings($input);

            return 1 === count($this->askedToNameFindings) ? $first() : $second();
        };
    }

    // Read from the encoded input, because the platform hands its model client the normalized messages, never the message bag.
    /**
     * @param array<mixed>|string|object $input
     */
    private function asksToNameFindings(array|string|object $input): bool
    {
        return str_contains(json_encode($input, JSON_THROW_ON_ERROR), substr(json_encode(ReviewerPrompt::NAME_FINDINGS_OR_APPROVE, JSON_THROW_ON_ERROR), 1, -1));
    }

    private function thrownBy(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    // Optionally on accounts, because config/packages/ai.php puts an AccountFailoverPlatform in front of every configured provider and its failover can hide a transport error thrown while sending, which the retry depends on.
    private function vendor(Closure $answer, bool $onAccounts = false): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        if ($onAccounts) {
            $platform = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $platform], new NoCooldowns(), new MockClock(self::NOW), new NullLogger(), new NoPinnedProviderCooldowns());
        }
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]), new NoCooldowns(), new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }

    private function request(): ReviewRequest
    {
        $open = new Finding('F1', Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);

        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$open], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
