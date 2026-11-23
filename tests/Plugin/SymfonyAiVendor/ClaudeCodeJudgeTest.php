<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\ClaudeCodePlatformFactory;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ConfinedClaudeCodeClient;
use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
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
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(JudgeAgent::class)]
#[CoversClass(ConfinedClaudeCodeClient::class)]
#[CoversClass(FinishedProcessResult::class)]
#[UsesClass(ClaudeCodePlatformFactory::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ProjectModelCatalog::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Provider::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(JudgePrompt::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Paths::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Fence::class)]
#[UsesClass(Kernel::class)]
final class ClaudeCodeJudgeTest extends KernelTestCase
{
    private const string NOW = '2026-11-14 12:00:00.000 UTC';

    public function testTheJudgeCallOnClaudeCodeReadsTheMarksFromTheRecordedStructuredOutput(): void
    {
        $judgement = $this->vendor(RecordedClaudeCodeCli::replaying('judgement-structured'))->judge('alpha', $this->request());

        self::assertEquals([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer)], $judgement->marks);
    }

    public function testTheJudgeCallOnClaudeCodeHoldsTheCliToTheJudgementSchema(): void
    {
        $cli = RecordedClaudeCodeCli::replaying('judgement-structured');

        $this->vendor($cli)->judge('alpha', $this->request());

        self::assertSame(json_encode(new ResponseFormatFactory()->create(Judgement::class)['json_schema']['schema'], JSON_THROW_ON_ERROR), $cli->options['json_schema']);
    }

    public function testTheJudgeCallOnClaudeCodeSettlesItsUsageOnce(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(RecordedClaudeCodeCli::replaying('judgement-structured'), $ledger)->judge('alpha', $this->request());

        self::assertSame(['started', 'settled'], array_column($ledger->payloads(), 'phase'));
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([90, 20], [$usages[0]['prompt'], $usages[0]['completion']]);
    }

    public function testAStructuredOutputThatIsNoJudgementMakesEveryBlockingFindingAct(): void
    {
        $judgement = $this->vendor(RecordedClaudeCodeCli::replaying('judgement-structured-not-a-judgement'))->judge('alpha', $this->request());

        self::assertEquals([new JudgedFinding('F1', Mark::Act)], $judgement->marks);
    }

    public function testAStructuredOutputThatLeavesOutAFindingMakesEveryBlockingFindingAct(): void
    {
        $judgement = $this->vendor(RecordedClaudeCodeCli::replaying('judgement-structured-only-f1'))->judge('alpha', $this->request());

        self::assertEquals([new JudgedFinding('F1', Mark::Act)], $judgement->marks);
    }

    private function vendor(RecordedClaudeCodeCli $cli, InMemoryLedger $ledger = new InMemoryLedger()): SymfonyAiVendor
    {
        $projects = ['alpha' => ['tiers' => ['light' => ['provider' => 'claude_code', 'model' => 'haiku']]]];
        $models = new ProjectModelCatalog($projects)->models(Provider::ClaudeCode);
        $platform = new ClaudeCodePlatformFactory(ScriptedReviewer::in(self::getContainer())->dispatcher)->create($cli, $models);
        $resolver = new ConfiguredTierResolver($projects, []);
        $platforms = new ServiceLocator([Provider::ClaudeCode->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }

    private function request(): JudgeRequest
    {
        return new JudgeRequest('Add the judge.', [$this->finding('F1', Severity::P2), $this->finding('F2', Severity::P3)], new UuidV7(), new Paths());
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'A finding.', FixableBy::Builder, false, null, null);
    }
}
