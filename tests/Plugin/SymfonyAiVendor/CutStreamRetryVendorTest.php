<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Generator;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
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
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
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
final class CutStreamRetryVendorTest extends KernelTestCase
{
    private const string NOW = '2026-11-13 12:00:00.000 UTC';

    private InMemoryLedger $ledger;

    /**
     * @var list<bool>
     */
    private array $streamed = [];

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
    }

    public function testAStreamCutOffMidAnswerLeavesItsUsageUnsettledOnceBeforeItsRetry(): void
    {
        $this->vendor($this->cutThen($this->cutAgain(...)))->review('alpha', Tier::Strong, $this->request());

        [, $unsettled] = $this->ledger->payloads();
        self::assertSame(['unsettled', IncompleteStreamException::class], [$unsettled['phase'], $unsettled['failure']]);
        /** @var list<array<string, int>> $usages */
        $usages = $unsettled['usages'];
        self::assertSame([100], array_column($usages, 'prompt'));
    }

    public function testAStreamCutBeforeItsAnswerCompletedIsTriedOnceMoreUnstreamedAndGivesThatAnswersVerdict(): void
    {
        $answer = $this->cutThen(static fn (): TextResult => new TextResult(VerdictDocument::fromTheDesign()->json()));

        $verdict = $this->vendor($answer)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertCount(1, $verdict->findings);
        self::assertSame([true, false], $this->streamed);
        self::assertSame(['started', 'unsettled', 'started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(IncompleteStreamException::class, $this->ledger->payloads()[1]['failure']);
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
        self::assertSame([IncompleteStreamException::class, IncompleteStreamException::class], [$this->ledger->payloads()[1]['failure'], $this->ledger->payloads()[3]['failure']]);
    }

    public function testAProviderFailureOnTheUnstreamedTryAfterACutStreamFollowsTheRuleOfThatFailure(): void
    {
        $vendor = $this->vendor($this->cutThen(static fn (): never => throw new ServerException(503)));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderFailedTransiently::class, $failure);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(ServerException::class, $this->ledger->payloads()[3]['failure']);
    }

    /**
     * @param Closure(): ResultInterface $retry
     */
    private function cutThen(Closure $retry): Closure
    {
        return function (Model $model, array|string|object $input, array $options) use ($retry): ResultInterface {
            $this->streamed[] = true === ($options['stream'] ?? false);

            return 1 === count($this->streamed) ? new StreamResult($this->cutMidAnswer()) : $retry();
        };
    }

    private function cutMidAnswer(): Generator
    {
        yield new TokenUsage(
            promptTokens: 100,
            model: 'big',
        );
        yield new TextDelta('{"verdict":"approve",');

        throw new IncompleteStreamException('The stream ended before its last event.');
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

    private function request(): ReviewRequest
    {
        $open = new Finding('F1', Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);

        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$open]), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function vendor(Closure $answer): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]), new NoCooldowns(), new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates(), new MockClock()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit, new MockClock(self::NOW));
    }
}
