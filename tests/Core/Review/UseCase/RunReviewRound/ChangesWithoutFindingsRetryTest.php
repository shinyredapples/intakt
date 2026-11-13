<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Fence;
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
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

#[Group('persistence')]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(Verdict::class)]
#[UsesClass(ChangesWithoutFindings::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(VendorAccountLimit::class)]
final class ChangesWithoutFindingsRetryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private int $calls = 0;

    /** @var list<mixed> */
    private array $limits = [];

    private SavedConversations $conversations;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->conversations = new SavedConversations();
    }

    public function testAChangesVerdictWithoutFindingsOnceGivesTheVerdictOfTheRetry(): void
    {
        $this->fixture->start(1);

        $this->deliverAll($this->answering([$this->emptyChanges(), $this->changesWithAFinding()]));

        self::assertSame([ReviewerState::Verdict], $this->states());
        self::assertSame([LedgerEventKind::VerdictReceived], $this->endings());
        self::assertSame(2, $this->calls);
        self::assertSame([8000, 8000], $this->limits);
        self::assertCount(2, array_unique($this->conversations->ids));
    }

    public function testAnEmptyChangesVerdictThenAnOutputLimitStopFailsTheReviewerWithoutAThirdCall(): void
    {
        $this->fixture->start(1);
        $answers = [$this->emptyChanges(), new MaxOutputTokensException('Truncated at the output limit.'), $this->changesWithAFinding()];

        $this->deliverAll($this->vendor(function () use ($answers): string {
            $answer = $answers[$this->calls++];

            return $answer instanceof MaxOutputTokensException ? throw $answer : $answer;
        }));

        self::assertSame([ReviewerState::Failed], $this->states());
        self::assertSame([MaxOutputTokensException::class], $this->failures());
        self::assertSame(2, $this->calls);
    }

    public function testAChangesVerdictWithoutFindingsAgainOnTheRetryIsRecordedAsAFailedReviewer(): void
    {
        $this->fixture->start(2);

        $this->deliverAll($this->answering([$this->emptyChanges(), $this->emptyChanges(), $this->changesWithAFinding()]));

        self::assertSame([ReviewerState::Failed, ReviewerState::Verdict], $this->states());
        self::assertSame([LedgerEventKind::ReviewerFailed, LedgerEventKind::VerdictReceived], $this->endings());
        self::assertSame([ChangesWithoutFindings::class], $this->failures());
    }

    public function testAChangesVerdictThatNamesAFindingIsNotAskedAgain(): void
    {
        $this->fixture->start(1);

        $this->deliverAll($this->answering([$this->changesWithAFinding(), $this->emptyChanges()]));

        self::assertSame([ReviewerState::Verdict], $this->states());
        self::assertSame(1, $this->calls);
    }

    public function testAnApprovalWithoutFindingsIsNotAskedAgain(): void
    {
        $this->fixture->start(1);

        $this->deliverAll($this->answering([VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('resolved', [])->with('findings', [])->json(), $this->emptyChanges()]));

        self::assertSame([ReviewerState::Verdict], $this->states());
        self::assertSame(1, $this->calls);
    }

    private function emptyChanges(): string
    {
        return VerdictDocument::fromTheDesign()->with('resolved', [])->with('findings', [])->json();
    }

    private function changesWithAFinding(): string
    {
        return VerdictDocument::fromTheDesign()->with('resolved', [])->json();
    }

    private function deliverAll(SymfonyAiVendor $vendor): void
    {
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    /**
     * @param list<string> $answers
     */
    private function answering(array $answers): SymfonyAiVendor
    {
        return $this->vendor(function (Model $model, array|string|object $input, array $options) use ($answers): string {
            $this->limits[] = $options[PlatformOptions::MAX_OUTPUT_TOKENS] ?? null;

            return $answers[$this->calls++];
        });
    }

    private function vendor(Closure $answer): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big', 'max_output' => 8000]]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $tiers = new TierPlatform($resolver, new ServiceLocator(['anthropic' => static fn (): PlatformInterface => $platform]), new NoCooldowns(), new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns($this->conversations, new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates(), new MockClock()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 2);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock);
    }

    /**
     * @return list<ReviewerState>
     */
    private function states(): array
    {
        return array_map(fn ($message): ReviewerState => $this->fixture->reviewer($message->reviewerId)->state(), $this->fixture->bus->messages);
    }

    /**
     * @return list<LedgerEventKind>
     */
    private function endings(): array
    {
        return array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, [LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerFailed], true)));
    }

    /**
     * @return list<mixed>
     */
    private function failures(): array
    {
        $failures = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if (LedgerEventKind::ReviewerFailed === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $failures[] = $payload['failure'] ?? null;
            }
        }

        return $failures;
    }
}
