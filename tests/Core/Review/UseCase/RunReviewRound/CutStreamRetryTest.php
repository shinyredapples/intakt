<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Generator;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

#[Group('persistence')]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(TierRoutedPlatform::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(VendorAccountLimit::class)]
final class CutStreamRetryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    /**
     * @var list<bool>
     */
    private array $streamed = [];

    public function testAStreamCutOnceGivesTheVerdictOfTheUnstreamedRetry(): void
    {
        $this->fixture->start(1);

        $this->deliverAll($this->vendor($this->cutThen(fn (): ResultInterface => new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json()))));

        self::assertSame([ReviewerState::Verdict], $this->states());
        self::assertSame(['changes', 'F3'], $this->storedVerdict());
        self::assertSame([true, false], $this->streamed);
        self::assertSame(['started', 'unsettled', 'started', 'settled'], $this->usagePhases());
    }

    public function testAStreamCutAgainOnTheUnstreamedRetryEndsTheReviewerBlockedWithBothTriesInTheLedger(): void
    {
        $this->fixture->start(1);

        $this->deliverAll($this->vendor($this->cutThen(static fn (): never => throw new IncompleteStreamException('The answer ended before it was complete.'))));

        self::assertSame([ReviewerState::Verdict], $this->states());
        self::assertSame(['blocked', Verdict::INCOMPLETE_STREAM], $this->storedVerdict());
        self::assertSame([true, false], $this->streamed);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], $this->usagePhases());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
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
        yield new TextDelta('{"verdict":"changes",');

        throw new IncompleteStreamException('The stream ended before its last event.');
    }

    private function vendor(Closure $answer): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big', 'max_output' => 8000]]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $tiers = new TierPlatform($resolver, new ServiceLocator(['anthropic' => static fn (): PlatformInterface => $platform]), new NoCooldowns(), new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 2);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock, self::createStub(ReviewServerAccesses::class), self::createStub(JobReviewer::class));
    }

    private function deliverAll(SymfonyAiVendor $vendor): void
    {
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    /**
     * @return list<ReviewerState>
     */
    private function states(): array
    {
        return array_map(fn ($message): ReviewerState => $this->fixture->reviewer($message->reviewerId)->state(), $this->fixture->bus->messages);
    }

    /**
     * @return array{string, string}
     */
    private function storedVerdict(): array
    {
        /** @var array{verdict: string, findings: list<array{id: string}>} $verdict */
        $verdict = json_decode((string) $this->fixture->reviewer($this->fixture->bus->messages[0]->reviewerId)->verdictJson(), true, 16, JSON_THROW_ON_ERROR);

        return [$verdict['verdict'], $verdict['findings'][0]['id']];
    }

    /**
     * @return list<mixed>
     */
    private function usagePhases(): array
    {
        $phases = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if (LedgerEventKind::UsageRecorded === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $phases[] = $payload['phase'] ?? null;
            }
        }

        return $phases;
    }
}
