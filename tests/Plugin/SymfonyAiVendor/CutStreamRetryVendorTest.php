<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Generator;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
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
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(ProviderFailedTransiently::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountCooldownWindows::class)]
final class CutStreamRetryVendorTest extends KernelTestCase
{
    private const string NOW = '2026-11-13 12:00:00.000 UTC';

    private const string ROUND = '01a19a97-48cf-70f1-bdae-965425786c46';

    private const string REVIEWER = '01a19a97-48cf-70f1-bdae-965425786c47';

    private InMemoryLedger $ledger;

    private RecordedRoundUpdates $updates;

    /**
     * @var list<bool>
     */
    private array $streamed = [];

    public function testAReviewerAskedAgainAfterACutStreamIsMarkedBeforeTheAnswerOfItsRetryAndTheCutAnswerIsNeverShown(): void
    {
        $answer = $this->cutThen(static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json()));
        $inARound = new ReviewerConversation('You review for correctness.', new UuidV7(), Uuid::fromString(self::ROUND), Uuid::fromString(self::REVIEWER));

        $this->vendor($answer)->review('alpha', Tier::Strong, $this->request($inARound));

        self::assertSame(['asked again '.self::REVIEWER, 'answer '.self::REVIEWER.' changes [P1 ...:12 A cart loses its discount]'], $this->updates->seen);
    }

    public function testTheFirstTryOfAReviewerIsNotMarkedAsAskedAgain(): void
    {
        $answer = static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json());
        $inARound = new ReviewerConversation('You review for correctness.', new UuidV7(), Uuid::fromString(self::ROUND), Uuid::fromString(self::REVIEWER));

        $this->vendor($answer)->review('alpha', Tier::Strong, $this->request($inARound));

        self::assertSame(['answer '.self::REVIEWER.' changes [P1 ...:12 A cart loses its discount]'], $this->updates->seen);
    }

    public function testAStreamCutOffMidAnswerLeavesItsUsageUnsettledOnceBeforeItsRetry(): void
    {
        $this->vendor($this->cutThen($this->cutAgain(...)))->review('alpha', Tier::Strong, $this->request());

        [, $unsettled] = $this->ledger->payloads();
        self::assertSame(['unsettled', StreamEndedEarly::class], [$unsettled['phase'], $unsettled['failure']]);
        /** @var list<array<string, int>> $usages */
        $usages = $unsettled['usages'];
        self::assertSame([100], array_column($usages, 'prompt'));
    }

    public function testAStreamCutBeforeItsAnswerCompletedIsTriedOnceMoreUnstreamedAndGivesThatAnswersVerdict(): void
    {
        $answer = $this->cutThen(static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json()));

        $verdict = $this->vendor($answer)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertCount(1, $verdict->findings);
        self::assertSame([true, false], $this->streamed);
        self::assertSame(['started', 'unsettled', 'started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(StreamEndedEarly::class, $this->ledger->payloads()[1]['failure']);
        self::assertSame(['big', 'big'], [$this->ledger->payloads()[0]['model'], $this->ledger->payloads()[2]['model']]);
        self::assertNotSame($this->ledger->payloads()[0]['call'], $this->ledger->payloads()[2]['call']);
    }

    public function testAStreamCutAgainOnItsUnstreamedTryBlocksTheReviewerWithTheFirstCutsReasonAndBothTriesLeftUnsettled(): void
    {
        $verdict = $this->vendor($this->cutThen($this->cutAgain(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Blocked, Verdict::INCOMPLETE_STREAM], [$verdict->verdict, $verdict->findings[0]->id]);
        self::assertStringContainsString('The stream ended before its last event.', $verdict->findings[0]->text);
        self::assertSame([true, false], $this->streamed);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame([StreamEndedEarly::class, IncompleteStreamException::class], [$this->ledger->payloads()[1]['failure'], $this->ledger->payloads()[3]['failure']]);
    }

    public function testAReviewerBlockedByACutStreamKeepsTheProviderTheModelAndTheChunksOfTheFirstCutAsEvidence(): void
    {
        $verdict = $this->vendor($this->cutThen($this->cutAgain(...)))->review('alpha', Tier::Strong, $this->request())->verdict;

        $evidence = $verdict->findings[0]->evidence;
        self::assertInstanceOf(Evidence::class, $evidence);
        self::assertSame('the call to anthropic, model big', $evidence->probe);
        self::assertSame("cut by: platform\nchunks: 2\nlast chunks: TokenUsage, TextDelta \"{\\\"verdict\\\":\\\"approve\\\",\"", $evidence->output);
    }

    public function testAReviewerBlockedByACutOutsideAStreamSaysNoChunksWereCounted(): void
    {
        $verdict = $this->vendor($this->cutAgain(...))->review('alpha', Tier::Strong, $this->request())->verdict;

        $evidence = $verdict->findings[0]->evidence;
        self::assertInstanceOf(Evidence::class, $evidence);
        self::assertSame(['the call to anthropic, model big', 'cut by: platform, outside a counted stream'], [$evidence->probe, $evidence->output]);
    }

    public function testAProviderFailureOnTheUnstreamedTryAfterACutStreamFollowsTheRuleOfThatFailure(): void
    {
        $vendor = $this->vendor($this->cutThen(static fn (): never => throw new ServerException(503)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderFailedTransiently::class, $failure);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(ServerException::class, $this->ledger->payloads()[3]['failure']);
    }

    public function testAnUnstreamedTryStoppedAtTheOutputLimitFailsTheReviewerWithoutAThirdCallWithMoreRoom(): void
    {
        $vendor = $this->vendor($this->cutThen(static fn (): never => throw new MaxOutputTokensException('Truncated at the output limit.')));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(MaxOutputTokensException::class, $failure);
        self::assertSame([true, false], $this->streamed);
    }

    public function testAnUnstreamedTryThatAsksForChangesWithoutAFindingFailsTheReviewerWithoutAThirdCall(): void
    {
        $vendor = $this->vendor($this->cutThen(static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->with('findings', [])->json())));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ChangesWithoutFindings::class, $failure);
        self::assertSame([true, false], $this->streamed);
    }

    public function testAnUnstreamedTryThatAnswersNoVerdictFailsTheReviewerWithoutAThirdCall(): void
    {
        $vendor = $this->vendor($this->cutThen(static fn (): TextResult => new TextResult('no verdict here')));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(VerdictUnreadable::class, $failure);
        self::assertSame([true, false], $this->streamed);
    }

    public function testAProviderOutageAfterTheFirstAnswerDeltaOnTheOnlyAccountBlocksTheReviewerWithoutARetry(): void
    {
        $cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new MockClock(self::NOW)), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new MockClock(self::NOW), new InMemoryLedger(), new NullLogger(), StoredSerializerFactory::create(), ['anthropic' => [Account::DEFAULT]]);
        $outage = $this->cutThen(static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->json()), new ServerException(503));
        $onlyAccount = static fn (PlatformInterface $platform): PlatformInterface => new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $platform], $cooldowns, new MockClock(self::NOW), new NullLogger(), new NoPinnedProviderCooldowns());

        $verdict = $this->vendor($outage, $onlyAccount, $cooldowns)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Blocked, Verdict::INCOMPLETE_STREAM], [$verdict->verdict, $verdict->findings[0]->id]);
        self::assertStringContainsString('The provider failed after the answer had started streaming.', $verdict->findings[0]->text);
        self::assertSame([true], $this->streamed);
        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
    }

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
        $this->updates = new RecordedRoundUpdates();
    }

    /**
     * @param Closure(): ResultInterface $retry
     */
    private function cutThen(Closure $retry, ?Throwable $cut = null): Closure
    {
        return function (Model $model, array|string|object $input, array $options) use ($retry, $cut): ResultInterface {
            $this->streamed[] = true === ($options['stream'] ?? false);

            return 1 === count($this->streamed) ? new StreamResult($this->cutMidAnswer($cut ?? new IncompleteStreamException('The stream ended before its last event.'))) : $retry();
        };
    }

    private function cutMidAnswer(Throwable $cut): Generator
    {
        yield new TokenUsage(
            promptTokens: 100,
            model: 'big',
        );
        yield new TextDelta('{"verdict":"approve",');

        throw $cut;
    }

    private function cutAgain(): never
    {
        throw new IncompleteStreamException('The answer ended before it was complete.');
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

    /**
     * @param ?Closure(PlatformInterface): PlatformInterface $accounts
     */
    private function vendor(Closure $answer, ?Closure $accounts = null, AccountCooldowns $cooldowns = new NoCooldowns()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $mock = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platform = $accounts instanceof Closure ? $accounts($mock) : $mock;
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]), $cooldowns, new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($this->updates), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }

    private function request(?ReviewerConversation $conversation = null): ReviewRequest
    {
        $open = new Finding('F1', Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);

        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$open], 1), $conversation ?? new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
