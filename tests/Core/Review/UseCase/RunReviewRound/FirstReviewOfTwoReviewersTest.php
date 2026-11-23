<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Fence;
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
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\RecordedAnswer;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
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
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Two reviewers, because the sample's first review runs a team of two (correctness and security); the answers follow the order the round delivers them.
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
final class FirstReviewOfTwoReviewersTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private int $calls = 0;

    public function testAFirstReviewWhoseFirstReviewerAnswersAVerdictOnlyAndThenAFindingShowsBothReviewersWithAVerdict(): void
    {
        $this->fixture->start(2);

        $this->deliverAll([RecordedAnswer::VERDICT_ONLY_CHANGES, RecordedAnswer::RETRY_WITH_A_FINDING, RecordedAnswer::APPROVE]);

        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict], $this->states());
        self::assertSame([['changes', 1], ['approve', 0]], $this->verdicts());
    }

    public function testAFirstReviewerThatAnswersBlockedWithoutAFindingOnBothTriesFailsAndTheOtherStillGivesItsVerdict(): void
    {
        $this->fixture->start(2);

        $this->deliverAll([RecordedAnswer::VERDICT_ONLY_BLOCKED, RecordedAnswer::VERDICT_ONLY_BLOCKED, RecordedAnswer::APPROVE]);

        self::assertSame([ReviewerState::Failed, ReviewerState::Verdict], $this->states());
        self::assertSame([['approve', 0]], $this->verdicts());
    }

    public function testAReviewersFindingIsStoredInTheDatabaseWithTheTitleAndKindTheReviewerGave(): void
    {
        $this->fixture->start(2);

        $this->deliverAll([RecordedAnswer::RETRY_WITH_A_FINDING, RecordedAnswer::APPROVE]);

        $stored = $this->fixture->entities->getConnection()->fetchOne("SELECT verdict FROM round_reviewer WHERE verdict LIKE '%\"changes\"%'");
        self::assertIsString($stored);
        /** @var array{findings: list<array<string, mixed>>} $verdict */
        $verdict = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['The discount raises the total', 'logic'], [$verdict['findings'][0]['title'] ?? null, $verdict['findings'][0]['kind'] ?? null]);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    /**
     * @param list<string> $answers
     */
    private function deliverAll(array $answers): void
    {
        $vendor = $this->vendor($answers);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    /**
     * @param list<string> $answers
     */
    private function vendor(array $answers): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $platform = MockPlatformFactory::createPlatform(fn (): string => RecordedAnswer::named($answers[$this->calls++]), eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $tiers = new TierPlatform($resolver, new ServiceLocator(['anthropic' => static fn (): PlatformInterface => $platform]), new NoCooldowns(), new NullLogger());
        $agent = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 2);

        return new SymfonyAiVendor($tiers, $resolver, $agent, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock, self::createStub(ReviewServerAccesses::class));
    }

    /**
     * @return list<ReviewerState>
     */
    private function states(): array
    {
        return array_map(fn ($message): ReviewerState => $this->fixture->reviewer($message->reviewerId)->state(), $this->fixture->bus->messages);
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function verdicts(): array
    {
        $verdicts = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if (LedgerEventKind::VerdictReceived === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $verdicts[] = [$payload['decision'] ?? null, $payload['findings'] ?? null];
            }
        }

        return $verdicts;
    }
}
