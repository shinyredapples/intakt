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
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
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
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ConfinedClaudeCodeClient::class)]
#[CoversClass(FinishedProcessResult::class)]
#[CoversClass(ClaudeCodePlatformFactory::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(ProjectModelCatalog::class)]
#[UsesClass(Provider::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Tier::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(Fence::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(Kernel::class)]
final class ClaudeCodeReviewerTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00.000 UTC';

    public function testACompleteRecordedStreamYieldsATypedVerdict(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::replaying('complete'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
    }

    public function testACompleteRecordedStreamSettlesItsUsageOnce(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(RecordedClaudeCodeCli::replaying('complete'), $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['started', 'settled'], array_column($ledger->payloads(), 'phase'));
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([120, 40], [$usages[0]['prompt'], $usages[0]['completion']]);
    }

    public function testTheCliGetsNoToolsNoProjectSettingsAndTheVerdictSchema(): void
    {
        $cli = RecordedClaudeCodeCli::replaying('complete');

        $this->vendor($cli)->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['tools', 'setting_sources', 'strict_mcp_config', 'no_session_persistence', 'append_system_prompt'], array_keys($cli->options));
        self::assertSame([[], '', true, true], [$cli->options['tools'], $cli->options['setting_sources'], $cli->options['strict_mcp_config'], $cli->options['no_session_persistence']]);
        $instruction = $cli->options['append_system_prompt'];
        self::assertIsString($instruction);
        self::assertStringContainsString('"json_schema"', $instruction);
    }

    public function testAStreamInWhichTheCliUsedOneOfItsOwnToolsStillYieldsItsVerdict(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::replaying('tool-use'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testTheSubscriptionReviewerCannotApproveAChangeItNeverRead(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::replaying('tool-use'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertStringContainsString('could not read', $verdict->findings[0]->text);
    }

    public function testACliThatExitsMidStreamEndsTheReviewerAsBlocked(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::exitingAfter('truncated', 143))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertStringContainsString('exit code 143', $verdict->findings[0]->text);
    }

    public function testACliKilledMidStreamEndsTheReviewerAsBlocked(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::killedAfter('truncated'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertStringContainsString('stopped by signal 15', $verdict->findings[0]->text);
    }

    public function testACliThatTimesOutMidStreamEndsTheReviewerAsBlocked(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::stalledAfter('truncated'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertStringContainsString('the CLI timed out', $verdict->findings[0]->text);
    }

    public function testACliThatFailsWithItsFinalResultIsAFailureAndNotABlockedReview(): void
    {
        try {
            $this->vendor(RecordedClaudeCodeCli::exitingAfter('failed', 1))->review('alpha', Tier::Strong, $this->request());
        } catch (RuntimeException $failure) {
            self::assertNotInstanceOf(IncompleteStreamException::class, $failure);
            self::assertStringContainsString('Not logged in', $failure->getMessage());

            return;
        }

        self::fail('A failed CLI run gave a verdict.');
    }

    public function testATruncatedRecordedStreamMakesTheBridgeRaiseIncompleteStream(): void
    {
        $platform = $this->claudeCodePlatform(RecordedClaudeCodeCli::replaying('truncated'));

        $result = $platform->invoke('sonnet', 'Review.', ['stream' => true])->getResult();

        self::assertInstanceOf(StreamResult::class, $result);
        $this->expectException(IncompleteStreamException::class);
        iterator_to_array($result->getContent());
    }

    public function testACompleteRecordedStreamIsReadToTheEndByTheBridge(): void
    {
        $platform = $this->claudeCodePlatform(RecordedClaudeCodeCli::replaying('complete'));

        $result = $platform->invoke('sonnet', 'Review.', ['stream' => true])->getResult();

        self::assertInstanceOf(StreamResult::class, $result);
        $text = '';
        foreach ($result->getContent() as $delta) {
            self::assertInstanceOf(TextDelta::class, $delta);
            $text .= $delta->getText();
        }
        self::assertStringContainsString('"verdict"', $text);
    }

    public function testATruncatedStreamEndsTheReviewerAsBlockedWithTheReason(): void
    {
        $verdict = $this->vendor(RecordedClaudeCodeCli::replaying('truncated'))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertStringContainsString('ended before its final result', $verdict->findings[0]->text);
    }

    public function testATruncatedStreamCountsItsUsageOnceAsUnsettled(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(RecordedClaudeCodeCli::replaying('truncated'), $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(IncompleteStreamException::class, $ledger->payloads()[1]['failure']);
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')]), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }

    private function claudeCodePlatform(RecordedClaudeCodeCli $cli): PlatformInterface
    {
        $models = new ProjectModelCatalog(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'sonnet']]]])->models(Provider::ClaudeCode);

        return new ClaudeCodePlatformFactory(ScriptedReviewer::in(self::getContainer())->dispatcher)->create($cli, $models);
    }

    private function vendor(RecordedClaudeCodeCli $cli, InMemoryLedger $ledger = new InMemoryLedger()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'sonnet']]]], []);
        $platform = $this->claudeCodePlatform($cli);
        $platforms = new ServiceLocator([Provider::ClaudeCode->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates(), new MockClock()), new ReviewerStopping(new StopRequests(new InMemoryLedger()))));

        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit);
    }
}
