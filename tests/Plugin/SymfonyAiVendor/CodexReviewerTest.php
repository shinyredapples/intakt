<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexPlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use Intakt\Core\Ai\Infrastructure\CompletedCodexTurn;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ConfinedCodexClient;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\SendFailures;
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
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\CodexLoginTest;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(ConfinedCodexClient::class)]
#[CoversClass(CompletedCodexTurn::class)]
#[CoversClass(CodexPlatformFactory::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(IsolatedCodexCli::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexSettings::class)]
#[UsesClass(CodexEffort::class)]
#[UsesClass(CodexTokenRefresh::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(Account::class)]
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
final class CodexReviewerTest extends KernelTestCase
{
    private const string NOW = '2026-11-02 12:00:00.000 UTC';
    private const string MODEL = 'gpt-5.3-codex';
    private const array PROJECTS = ['alpha' => ['tiers' => ['strong' => ['provider' => 'codex', 'model' => self::MODEL]]]];

    private string $root;

    private string $path;

    private InMemoryLedger $ledger;

    private AccountCooldownWindows $cooldowns;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-codex-reviewer-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/codex/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->root.'/bin:'.$this->path);
        $this->ledger = new InMemoryLedger();
        $this->cooldowns = new AccountCooldownWindows(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new MockClock(self::NOW), $this->ledger, new NullLogger(), ['codex' => ['team']]);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testACompleteRecordedStreamYieldsATypedVerdict(): void
    {
        $this->replaying('complete');

        $verdict = $this->review()->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertSame(['F3', Severity::P1], [$verdict->findings[0]->id, $verdict->findings[0]->severity]);
    }

    public function testACompleteRecordedStreamRecordsItsUsageOnceForItsAccount(): void
    {
        $this->replaying('complete');

        $this->review();

        self::assertSame(['started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $this->ledger->payloads()[1]['usages'];
        self::assertCount(1, $usages);
        self::assertSame(['codex.team', 2400, 180, 1024], [$usages[0]['account'], $usages[0]['prompt'], $usages[0]['completion'], $usages[0]['cached']]);
    }

    public function testTheCliGetsTheVerdictSchemaAndTheRoleInItsPromptAndNoOptionOfTheAgent(): void
    {
        $this->replaying('complete');

        $this->review();

        $arguments = $this->arguments();
        $prompt = (string) array_last($arguments);
        self::assertStringStartsWith('[System]', $prompt);
        self::assertStringContainsString("You review for correctness.\n\nIn this session no tool is available", $prompt);
        self::assertStringContainsString('"json_schema"', $prompt);
        self::assertNotContains('--response-format', $arguments);
        self::assertNotContains('--allowedTools', $arguments);
    }

    public function testTheReviewerCannotApproveAChangeItNeverRead(): void
    {
        $this->replaying('tool-use');

        $verdict = $this->review()->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testTextThatOnlyMentionsALimitIsAFailedReviewAndNoCooldown(): void
    {
        $this->replaying('limit-text');

        $verdict = $this->review()->verdict;

        self::assertTrue($verdict->isFailedReview());
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
        self::assertSame([LedgerEventKind::UsageRecorded, LedgerEventKind::UsageRecorded], $this->ledger->kinds());
    }

    public function testTextThatOnlyMentionsALimitStillRecordsItsUsageOnce(): void
    {
        $this->replaying('limit-text');

        $this->review();

        self::assertSame(['started', 'settled'], array_column($this->ledger->payloads(), 'phase'));
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $this->ledger->payloads()[1]['usages'];
        self::assertSame([['codex.team', 2400]], array_map(static fn (array $usage): array => [$usage['account'], $usage['prompt']], $usages));
    }

    public function testAFailedTurnIsAFailureOfTheReviewAndNoAccountOutcome(): void
    {
        $this->replaying('failed', 1);

        $failure = $this->failureOfReview();

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertNotInstanceOf(ProviderRateLimited::class, $failure);
        self::assertNotInstanceOf(NoProviderLeft::class, $failure);
        self::assertNotInstanceOf(VendorAccountOut::class, $failure);
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
    }

    public function testAStreamThatEndsBeforeItsTurnCompletedIsAFailedReview(): void
    {
        $this->replaying('truncated');

        $verdict = $this->review()->verdict;

        self::assertTrue($verdict->isFailedReview());
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertStringContainsString('ended before its turn completed', $verdict->findings[0]->text);
    }

    public function testAStreamThatEndsBeforeItsTurnCompletedCountsItsCallOnceAsUnsettled(): void
    {
        $this->replaying('truncated');

        $this->review();

        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame(IncompleteStreamException::class, $this->ledger->payloads()[1]['failure']);
    }

    // Replays a recording as the codex CLI on PATH, so the call goes through the login handoff and the bridge's own process result as a live run would.
    private function replaying(string $recording, int $exitCode = 0): void
    {
        $script = sprintf("#!/bin/sh\nprintf '%%s\\0' \"\$@\" > '%s'\ncat '%s'\nexit %d\n", $this->root.'/arguments', __DIR__.'/Fixtures/codex/'.$recording.'.jsonl', $exitCode);
        new Filesystem()->dumpFile($this->root.'/bin/codex', $script);
        chmod($this->root.'/bin/codex', 0o700);
    }

    /**
     * @return list<string>
     */
    private function arguments(): array
    {
        return explode("\0", rtrim((string) file_get_contents($this->root.'/arguments'), "\0"));
    }

    private function failureOfReview(): Throwable
    {
        try {
            $this->review();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The review gave a verdict.');
    }

    private function review(): VendorReview
    {
        return $this->vendor()->review('alpha', Tier::Strong, $this->request());
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')]), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }

    private function vendor(): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(self::PROJECTS, []);
        $clock = new MockClock(self::NOW);
        $logins = new CodexLogins($this->root.'/codex', new CodexTokenRefresh(new MockHttpClient(), $clock), new LockFactory(new InMemoryStore()));
        $cli = new IsolatedCodexCli($logins, new CodexSettings(self::MODEL, CodexEffort::High), 'team');
        $codex = new CodexPlatformFactory(ScriptedReviewer::in(self::getContainer())->dispatcher)->create($cli, new ProjectModelCatalog(self::PROJECTS)->models(Provider::Codex));
        $accounts = new AccountFailoverPlatform(Provider::Codex, ['team' => $codex], $this->cooldowns, $clock, new NullLogger());
        $platforms = new ServiceLocator([Provider::Codex->value => static fn (): PlatformInterface => $accounts]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new InMemoryMessageStore());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $this->cooldowns, new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, $clock, new NullLogger()), $limit);
    }
}
