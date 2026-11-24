<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewTools;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountErrorReported;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\CodexAccountFailure;
use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexMessageBagNormalizer;
use Intakt\Core\Ai\Infrastructure\CodexPlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use Intakt\Core\Ai\Infrastructure\CompletedCodexTurn;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ConfinedCodexClient;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\IssuedReviewServerAccesses;
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
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\CodexLoginTest;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
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
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(ConfinedCodexClient::class)]
#[CoversClass(CompletedCodexTurn::class)]
#[CoversClass(CodexPlatformFactory::class)]
#[CoversClass(CodexMessageBagNormalizer::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(CodexAccountFailure::class)]
#[UsesClass(IsolatedCodexCli::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexSettings::class)]
#[UsesClass(CodexEffort::class)]
#[UsesClass(CodexTokenRefresh::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(AccountErrorReported::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(Account::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictUnreadable::class)]
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
#[UsesClass(ReviewServerAccess::class)]
#[UsesClass(IssuedReviewServerAccesses::class)]
#[UsesClass(ReviewCredential::class)]
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

    private MockHttpClient $tokenEndpoint;

    public function testACompleteRecordedStreamYieldsATypedVerdict(): void
    {
        $this->replaying('complete');

        $verdict = $this->review()->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertEquals([new ResolvedFinding('F1'), new ResolvedFinding('F2')], $verdict->resolved);
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

    public function testTheCliGetsTheTaskTheDiffAndTheOpenFindingsInItsPrompt(): void
    {
        $this->replaying('complete');

        $this->review();

        $prompt = (string) array_last($this->arguments());
        self::assertStringContainsString('Review the change.', $prompt);
        self::assertStringContainsString('diff --git a/a b/a', $prompt);
        self::assertStringContainsString('Earlier.', $prompt);
    }

    public function testACompletedTurnWithoutAnAnswerFailsTheReviewer(): void
    {
        $this->replaying('no-answer');

        $failure = $this->failureOfReview();

        self::assertSame(RuntimeException::class, $failure::class);
        self::assertStringContainsString('does not contain a text field', $failure->getMessage());
    }

    public function testACompletedTurnWithoutAnAnswerStillRecordsItsUsageOnce(): void
    {
        $this->replaying('no-answer');

        $this->failureOfReview();

        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $this->ledger->payloads()[1]['usages'];
        self::assertSame([['codex.team', 2300, 70, 512]], array_map(static fn (array $usage): array => [$usage['account'], $usage['prompt'], $usage['completion'], $usage['cached']], $usages));
    }

    public function testACompletedTurnWhoseCliThenExitsNonZeroFailsTheReviewer(): void
    {
        $this->replaying('no-answer', 1);

        $failure = $this->failureOfReview();

        self::assertSame(RuntimeException::class, $failure::class);
        self::assertStringContainsString('process failed', $failure->getMessage());
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
    }

    public function testACompletedTurnWhoseCliThenExitsNonZeroStillRecordsItsUsageOnce(): void
    {
        $this->replaying('no-answer', 1);

        $this->failureOfReview();

        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $this->ledger->payloads()[1]['usages'];
        self::assertSame([['codex.team', 2300, 70, 512]], array_map(static fn (array $usage): array => [$usage['account'], $usage['prompt'], $usage['completion'], $usage['cached']], $usages));
    }

    public function testAFailedTurnWithoutAReportedUsageRecordsNone(): void
    {
        $this->replaying('failed', 1);

        $this->failureOfReview();

        self::assertSame([], $this->ledger->payloads()[1]['usages']);
    }

    public function testTheReviewerCannotApproveAChangeItNeverRead(): void
    {
        $this->replaying('tool-use');

        $verdict = $this->review()->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliThatReadThroughTheReviewServerCounts(): void
    {
        $this->replaying('review-server-read');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testAnApprovalFromACliGivenTheReviewServerThatOnlyFailedAReadOnAnotherServerIsRefused(): void
    {
        $this->replaying('tool-use');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliGivenTheReviewServerWhoseReadThroughItFailedIsRefused(): void
    {
        $this->replaying('review-server-read-failed');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliGivenTheReviewServerThatRefusedItsOnlyReadIsRefused(): void
    {
        $this->replaying('review-server-read-refused');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliGivenTheReviewServerThatReadOnlyThroughAnotherServerIsRefused(): void
    {
        $this->replaying('other-server-read');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliWhoseReadThroughTheReviewServerOnlyStartedIsRefused(): void
    {
        $this->replaying('review-server-read-started');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalWhoseTextEmbedsAReadThroughTheReviewServerIsRefused(): void
    {
        $this->replaying('review-server-read-in-text');

        $verdict = $this->review($this->servers(), $this->requestInRound())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnApprovalFromACliStartedWithoutTheReviewServerForWantOfARoundIsStillRefused(): void
    {
        $this->replaying('review-server-read');

        $verdict = $this->review($this->servers())->verdict;

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
        self::assertSame([], $this->serverSettings());
    }

    public function testTheCliArgumentsNameExactlyTheReviewServerAndThePinnedModelAndEffort(): void
    {
        $this->replaying('review-server-read');

        $this->review($this->servers(), $this->requestInRound());

        $arguments = $this->arguments();
        self::assertSame([
            'mcp_servers.intakt-review.url="http://localhost:7780/mcp/review"',
            'mcp_servers.intakt-review.bearer_token_env_var="INTAKT_REVIEW_SERVER_BEARER"',
            'mcp_servers.intakt-review.enabled_tools=["read_file","list_directory","search_files"]',
            'mcp_servers.intakt-review.required=true',
        ], $this->serverSettings());
        self::assertSame(['--model', self::MODEL], array_slice($arguments, (int) array_search('--model', $arguments, true), 2));
        self::assertContains('model_reasoning_effort="high"', $arguments);
        self::assertContains('web_search="disabled"', $arguments);
        self::assertContains('--ignore-user-config', $arguments);
    }

    public function testTheRoundsBearerReachesTheCliOnlyThroughItsEnvironment(): void
    {
        $this->replaying('review-server-read');

        $this->review($this->servers(), $this->requestInRound());

        self::assertSame('round-credential', file_get_contents($this->root.'/bearer'));
        self::assertStringNotContainsString('round-credential', implode(' ', $this->arguments()));
    }

    public function testTheCliIsToldToReadTheFilesItNeedsThroughTheToolsWhenItIsGivenTheReviewServer(): void
    {
        $this->replaying('review-server-read');

        $this->review($this->servers(), $this->requestInRound());

        $prompt = (string) array_last($this->arguments());
        self::assertStringContainsString('only the read-only tools of the server intakt-review are available: read the files you need through them before you give a verdict', $prompt);
        self::assertStringNotContainsString('no tool is available', $prompt);
    }

    public function testAReviewWhoseServerAccessCannotBeIssuedIsBlockedForWantOfToolsWithoutStartingTheCli(): void
    {
        $this->replaying('review-server-read');

        $verdict = $this->review(new IssuedReviewServerAccesses(new ReviewCredential(''), 'http://localhost:7780/mcp/review'), $this->requestInRound())->verdict;

        self::assertSame(Verdict::NO_TOOLS, $verdict->findings[0]->id);
        self::assertFileDoesNotExist($this->root.'/arguments');
    }

    public function testTextThatOnlyMentionsALimitIsRefusedAsUnreadableAndCoolsNoAccountDown(): void
    {
        $this->replaying('limit-text');

        $failure = $this->failureOfReview();

        self::assertSame(VerdictUnreadable::class, $failure::class);
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
    }

    public function testTextThatOnlyMentionsALimitRecordsTheUsageOfItsCallAndOfTheRetryOnceEach(): void
    {
        $this->replaying('limit-text');

        $this->failureOfReview();

        $payloads = $this->ledger->payloads();
        self::assertSame(['started', 'settled', 'started', 'settled'], array_column($payloads, 'phase'));
        foreach ([$payloads[1], $payloads[3]] as $settled) {
            /** @var list<array<string, int|string|null>> $usages */
            $usages = $settled['usages'];
            self::assertSame([['codex.team', 2400]], array_map(static fn (array $usage): array => [$usage['account'], $usage['prompt']], $usages));
        }
    }

    public function testAFailedTurnIsAFailureOfTheReviewAndNoAccountOutcome(): void
    {
        $this->replaying('failed', 1);

        $failure = $this->failureOfReview();

        self::assertSame(RuntimeException::class, $failure::class);
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
    }

    public function testAUsageLimitOfThePlanCoolsTheAccountDownInsteadOfFailingTheReview(): void
    {
        $this->replaying('usage-limit', 1);

        $failure = $this->failureOfReview();

        self::assertSame(ProviderRateLimited::class, $failure::class);
        self::assertTrue($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
        self::assertFalse($this->cooldowns->isRejected(new Account(Provider::Codex, 'team')));
    }

    public function testAUsageLimitFromACliThatExitsZeroStillCoolsTheAccountDown(): void
    {
        $this->replaying('usage-limit', 0);

        $failure = $this->failureOfReview();

        self::assertSame(ProviderRateLimited::class, $failure::class);
        self::assertTrue($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
    }

    public function testAModelNotAvailableForThePlanIsAnAccountErrorInsteadOfAFailedReview(): void
    {
        $this->replaying('model-not-in-plan', 1);

        $failure = $this->failureOfReview();

        self::assertSame(ProviderRejectedTheKey::class, $failure::class);
        self::assertTrue($this->cooldowns->isRejected(new Account(Provider::Codex, 'team')));
    }

    public function testALostLoginIsAnAccountErrorInsteadOfAFailedReview(): void
    {
        $this->replaying('login-lost', 1);

        $failure = $this->failureOfReview();

        self::assertSame(ProviderRejectedTheKey::class, $failure::class);
        self::assertTrue($this->cooldowns->isRejected(new Account(Provider::Codex, 'team')));
    }

    public function testAnAccountErrorCountsItsCallOnceAsUnsettledWithoutUsage(): void
    {
        $this->replaying('login-lost', 1);

        $this->failureOfReview();

        self::assertSame([LedgerEventKind::UsageRecorded, LedgerEventKind::AccountCooledDown, LedgerEventKind::AccountErrorReported, LedgerEventKind::UsageRecorded], $this->ledger->kinds());
        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame([], $this->ledger->payloads()[3]['usages']);
    }

    public function testALostLoginIsReportedToTheOwnerOnceWithItsAccount(): void
    {
        $this->replaying('login-lost', 1);

        $this->failureOfReview();
        $this->failureOfReview();

        $reported = array_keys($this->ledger->kinds(), LedgerEventKind::AccountErrorReported, true);
        self::assertCount(1, $reported);
        self::assertSame('codex.team', $this->ledger->payloads()[$reported[0]]['account']);
    }

    public function testAUsageLimitOfThePlanIsNotReportedToTheOwner(): void
    {
        $this->replaying('usage-limit', 1);

        $this->failureOfReview();

        self::assertNotContains(LedgerEventKind::AccountErrorReported, $this->ledger->kinds());
    }

    public function testARefreshTheTokenEndpointRefusesBeforeTheCallIsSentIsAnAccountErrorReportedOnce(): void
    {
        $this->replaying('complete');
        new Filesystem()->dumpFile($this->root.'/codex/logins/team/auth.json', CodexLoginTest::json('2026-10-01T00:00:00Z'));
        $this->tokenEndpoint = new MockHttpClient(new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 401]));

        $failure = $this->failureOfReview();

        self::assertSame(ProviderRejectedTheKey::class, $failure::class);
        self::assertTrue($this->cooldowns->isRejected(new Account(Provider::Codex, 'team')));
        self::assertFileDoesNotExist($this->root.'/arguments');
        self::assertSame([LedgerEventKind::UsageRecorded, LedgerEventKind::AccountCooledDown, LedgerEventKind::AccountErrorReported, LedgerEventKind::UsageRecorded], $this->ledger->kinds());
        self::assertSame(['started', 'unsettled'], array_column($this->ledger->payloads(), 'phase'));
        self::assertSame([], $this->ledger->payloads()[3]['usages']);
    }

    public function testAReviewerTextThatRepeatsTheLostLoginMessageIsNoAccountError(): void
    {
        $this->replaying('login-text', 1);

        $failure = $this->failureOfReview();

        self::assertSame(RuntimeException::class, $failure::class);
        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::Codex, 'team')));
        self::assertFalse($this->cooldowns->isRejected(new Account(Provider::Codex, 'team')));
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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-codex-reviewer-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/codex/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->root.'/bin:'.$this->path);
        $this->ledger = new InMemoryLedger();
        $this->tokenEndpoint = new MockHttpClient();
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new MockClock(self::NOW)), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new MockClock(self::NOW), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), ['codex' => ['team']]);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    // Replays a recording as the codex CLI on PATH, so the call goes through the login handoff and the bridge's own process result as a live run would.
    private function replaying(string $recording, int $exitCode = 0): void
    {
        $script = sprintf("#!/bin/sh\nprintf '%%s\\0' \"\$@\" > '%s'\nprintf '%%s' \"\$INTAKT_REVIEW_SERVER_BEARER\" > '%s'\ncat '%s'\nexit %d\n", $this->root.'/arguments', $this->root.'/bearer', __DIR__.'/Fixtures/codex/'.$recording.'.jsonl', $exitCode);
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

    /**
     * @return list<string>
     */
    private function serverSettings(): array
    {
        return array_values(array_filter($this->arguments(), static fn (string $argument): bool => str_starts_with($argument, 'mcp_servers.')));
    }

    private function servers(): ReviewServerAccesses
    {
        $servers = self::createStub(ReviewServerAccesses::class);
        $servers->method('forRound')->willReturn(new ReviewServerAccess('intakt-review', 'http://localhost:7780/mcp/review', 'round-credential', new ReviewTools('read_file', 'list_directory', 'search_files')));

        return $servers;
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

    private function review(?ReviewServerAccesses $servers = null, ?ReviewRequest $request = null): VendorReview
    {
        return $this->vendor($servers)->review('alpha', Tier::Strong, $request ?? $this->request());
    }

    private function requestInRound(): ReviewRequest
    {
        $request = $this->request();

        return new ReviewRequest($request->checkout, $request->brief, new ReviewerConversation('You review for correctness.', new UuidV7(), new UuidV7()));
    }

    private function vendor(?ReviewServerAccesses $servers): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(self::PROJECTS, []);
        $clock = new MockClock(self::NOW);
        $logins = new CodexLogins($this->root.'/codex', new CodexTokenRefresh($this->tokenEndpoint, $clock), new LockFactory(new InMemoryStore()));
        $cli = new IsolatedCodexCli($logins, new CodexSettings(self::MODEL, CodexEffort::High), 'team');
        $codex = new CodexPlatformFactory(ScriptedReviewer::in(self::getContainer())->dispatcher)->create($cli, new ProjectModelCatalog(self::PROJECTS)->models(Provider::Codex));
        $accounts = new AccountFailoverPlatform(Provider::Codex, ['team' => $codex], $this->cooldowns, $clock, new NullLogger(), new NoPinnedProviderCooldowns());
        $platforms = new ServiceLocator([Provider::Codex->value => static fn (): PlatformInterface => $accounts]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $this->cooldowns, new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, $clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $clock, $servers ?? $this->servers(), self::createStub(JobReviewer::class));
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }
}
