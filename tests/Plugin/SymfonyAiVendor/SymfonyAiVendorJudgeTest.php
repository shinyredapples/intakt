<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\VerdictParser;
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
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(JudgeAgent::class)]
#[UsesClass(JudgePrompt::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Fence::class)]
#[UsesClass(UntrustedSource::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Paths::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class SymfonyAiVendorJudgeTest extends KernelTestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    public function testTheVendorJudgesOnTheLightTierAndReturnsTheMarks(): void
    {
        $models = [];
        $answer = static function (Model $model) use (&$models): string {
            $models[] = $model->getName();

            return '{"marks":[{"id":"F1","mark":"defer"},{"id":"F2","mark":"reject"}]}';
        };

        $judgement = $this->vendor($answer)->judge('alpha', $this->request());

        self::assertEquals([new JudgedFinding('F1', Mark::Defer), new JudgedFinding('F2', Mark::Reject)], $judgement->marks);
        self::assertSame(['small'], $models);
    }

    public function testAnAnsweredJudgementIsRecordedAsStartedThenSettledOnTheLightTier(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => '{"marks":[{"id":"F1","mark":"act"},{"id":"F2","mark":"defer"}]}', $ledger)->judge('alpha', $this->request());

        self::assertSame(['started', 'settled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame('light', $ledger->payloads()[0]['tier']);
    }

    public function testTheJudgeCallIsRecordedWithTheRoundItJudges(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => '{"marks":[{"id":"F1","mark":"act"},{"id":"F2","mark":"defer"}]}', $ledger)->judge('alpha', $this->request());

        self::assertSame(self::ROUND, $ledger->payloads()[0]['round'] ?? null);
    }

    public function testACutOffStreamMakesEveryBlockingFindingActAndLeavesTheCallUnsettled(): void
    {
        $ledger = new InMemoryLedger();
        $cutOff = static fn (): never => throw new IncompleteStreamException('The stream ended before its last event.');

        $judgement = $this->vendor($cutOff, $ledger)->judge('alpha', $this->request());

        self::assertEquals([new JudgedFinding('F1', Mark::Act)], $judgement->marks);
        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(IncompleteStreamException::class, $ledger->payloads()[1]['failure']);
    }

    public function testAFailedJudgeCallIsATypedProviderErrorAndLeftUnsettled(): void
    {
        $ledger = new InMemoryLedger();
        $limited = static fn (): never => throw new RateLimitExceededException(30);

        try {
            $this->vendor($limited, $ledger)->judge('alpha', $this->request());
            self::fail('A failed judge call must stay an error, never a judgement.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(30, $rateLimited->retryAfterSeconds);
        }

        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(RateLimitExceededException::class, $ledger->payloads()[1]['failure']);
    }

    private function vendor(Closure $answer, InMemoryLedger $ledger = new InMemoryLedger()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'strong' => ['provider' => 'anthropic', 'model' => 'big'],
            'light' => ['provider' => 'anthropic', 'model' => 'small'],
        ]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $usage = new UsageLedger($ledger, new MockClock('2026-10-27 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());

        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), $usage, $limit, new MockClock('2026-10-27 12:00:00 UTC'), self::createStub(ReviewServerAccesses::class), self::createStub(JobReviewer::class));
    }

    private function request(): JudgeRequest
    {
        return new JudgeRequest('Add a cart.', [
            new Finding('F1', Severity::P2, null, null, null, 'Text.', FixableBy::Builder, false, null, null),
            new Finding('F2', Severity::P3, null, null, null, 'Nit.', FixableBy::Builder, false, null, null),
        ], Uuid::fromString(self::ROUND), new Paths());
    }
}
