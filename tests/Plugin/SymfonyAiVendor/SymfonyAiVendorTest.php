<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderWait;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\ManagedMessageStoreInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(UsageLedger::class)]
#[CoversClass(ProviderRateLimited::class)]
#[CoversClass(ProviderFailedTransiently::class)]
#[CoversClass(UsageReservation::class)]
#[CoversClass(UsageNotRecorded::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(ProviderWait::class)]
#[UsesClass(NoProviderLeft::class)]
#[UsesClass(ProviderRejectedTheKey::class)]
#[UsesClass(Tier::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Verdict::class)]
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
#[UsesClass(Checkout::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountBusy::class)]
#[UsesClass(VendorReview::class)]
#[UsesClass(Kernel::class)]
final class SymfonyAiVendorTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00.000 UTC';

    private VendorAccountLimit $limit;

    #[Override]
    protected function setUp(): void
    {
        $this->limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
    }

    public function testAStructuredCallReturnsTheVerdictTheModelAnswered(): void
    {
        $vendor = $this->vendorAnswering(VerdictDocument::fromTheDesign()->json());

        $verdict = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertCount(1, $verdict->findings);
    }

    public function testTheTierSelectsTheModelTheCallIsSentTo(): void
    {
        $answer = static fn (Model $model): string => VerdictDocument::fromTheDesign()->with('follow_ups', [$model->getName()])->json();

        $verdict = $this->vendor($answer)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(['big'], $verdict->follow_ups);
    }

    public function testAnApprovalFromAReviewerThatReadsTheCheckoutCounts(): void
    {
        $vendor = $this->vendorAnswering(VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('findings', [])->json());

        $verdict = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testAnAnswerThatIsNotAVerdictCountsAsChanges(): void
    {
        $vendor = $this->vendorAnswering('not a verdict');

        $verdict = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    private function vendorAnswering(string $output): SymfonyAiVendor
    {
        return $this->vendor(static fn (): string => $output);
    }

    public function testAnAnsweredCallIsRecordedOnceAsStartedThenSettledWithItsTokens(): void
    {
        $ledger = new InMemoryLedger();
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json());

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request());

        [$started, $settled] = $ledger->payloads();
        self::assertSame(2, $ledger->count());
        self::assertEquals([LedgerEventKind::UsageRecorded, LedgerEventKind::UsageRecorded], $ledger->kinds());
        self::assertSame(['started', 'settled'], [$started['phase'], $settled['phase']]);
        self::assertSame($started['call'], $settled['call']);
        self::assertSame(['alpha', 'strong'], [$started['project'], $started['tier']]);
        /** @var list<array<string, int>> $usages */
        $usages = $settled['usages'];
        self::assertSame([100, 20, 60, 5000], [$usages[0]['prompt'], $usages[0]['completion'], $usages[0]['cached'], $usages[0]['remaining']]);
    }

    public function testACallThatFailsIsLeftUnsettledOnce(): void
    {
        $ledger = new InMemoryLedger();
        $failing = static fn (): never => throw new RuntimeException('provider down');

        try {
            $this->vendor($failing, $ledger)->review('alpha', Tier::Strong, $this->request());
            self::fail('The failed call must stay an exception.');
        } catch (RuntimeException) {
            // Expected, because a failed call must not be turned into a verdict.
        }

        [$started, $unsettled] = $ledger->payloads();
        self::assertSame(2, $ledger->count());
        self::assertSame(['started', 'unsettled'], [$started['phase'], $unsettled['phase']]);
        self::assertSame($started['call'], $unsettled['call']);
        self::assertSame(RuntimeException::class, $unsettled['failure']);
    }

    public function testACallThatFailsAfterAnAnsweredRoundLeavesItUnsettledWithThatRoundsTokens(): void
    {
        $ledger = new InMemoryLedger();
        $step = 0;
        $rounds = static function () use (&$step): ToolCallResult {
            if (0 === $step++) {
                return self::carrying(new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]), new TokenUsage(promptTokens: 100, model: 'big'));
            }

            throw new ServerException(503);
        };

        try {
            $this->vendor($rounds, $ledger)->review('alpha', Tier::Strong, $this->request());
            self::fail('The failed round must stay an exception.');
        } catch (ProviderFailedTransiently) {
            // Expected, because the second round failed.
        }

        [, $unsettled] = $ledger->payloads();
        self::assertSame(['unsettled', ServerException::class], [$unsettled['phase'], $unsettled['failure']]);
        /** @var list<array<string, int>> $usages */
        $usages = $unsettled['usages'];
        self::assertSame([100], array_column($usages, 'prompt'));
    }

    // The own provider cannot read the checkout and is out for the first round only, so a review that picked its provider per round would end on it with its approval judged as the substitute's.
    public function testEveryRoundOfAReviewStaysOnTheSubstituteItBeganOnSoTheBlindOwnProviderNeverAnswersIt(): void
    {
        $sent = [];
        $checks = 0;
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturnCallback(static function (Provider $provider) use (&$checks): int {
            return Provider::ClaudeCode === $provider && 0 === $checks++ ? 60 : 0;
        });
        $approve = VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('findings', [])->json();
        $round = 0;
        $reads = static function () use (&$sent, &$round, $approve): ToolCallResult|string {
            $sent[] = 'openai';

            return 0 === $round++ ? new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]) : $approve;
        };
        $blind = static function () use (&$sent, $approve): string {
            $sent[] = 'claude_code';

            return $approve;
        };

        $review = $this->substitutedVendor($blind, $reads, $cooldowns)->review('alpha', Tier::Strong, $this->request());

        self::assertSame(['openai', 'openai'], $sent);
        self::assertSame(Provider::OpenAi, $review->substitute);
        self::assertSame(Decision::Approve, $review->verdict->verdict);
    }

    public function testAReviewOnAProviderWhosePlacesAreAllTakenIsRefusedBeforeAnyUsageOrCall(): void
    {
        $ledger = new InMemoryLedger();
        $sent = false;
        $answer = static function () use (&$sent): string {
            $sent = true;

            return VerdictDocument::fromTheDesign()->json();
        };

        try {
            $this->limit->run(Provider::Anthropic, fn (): VendorReview => $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request()));
            self::fail('A review on a full account must be refused.');
        } catch (VendorAccountBusy) {
            // Expected, because the only place of the account is taken.
        }

        self::assertFalse($sent);
        self::assertSame(0, $ledger->count());
    }

    public function testASubstituteHoldsAPlaceOfItsOwnProviderAndNotOfTheTiersProvider(): void
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturnCallback(static fn (Provider $provider): int => Provider::ClaudeCode === $provider ? 60 : 0);
        $answer = static fn (): string => VerdictDocument::fromTheDesign()->json();
        $vendor = $this->substitutedVendor($answer, $answer, $cooldowns);

        $review = $this->limit->run(Provider::ClaudeCode, fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertSame(Provider::OpenAi, $review->substitute);
        $this->expectException(VendorAccountBusy::class);
        $this->limit->run(Provider::OpenAi, fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));
    }

    public function testATierWhoseOwnProviderIsOutWithoutSubstitutesWaitsWithoutReservingUsage(): void
    {
        $ledger = new InMemoryLedger();
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturn(45);
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform('never sent')]);
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, $this->reviewer(), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger()), $this->limit);

        try {
            $vendor->review('alpha', Tier::Strong, $this->request());
            self::fail('A provider whose every account is out was called.');
        } catch (ProviderRateLimited $limited) {
            self::assertSame(45, $limited->retryAfterSeconds);
        }

        self::assertSame(0, $ledger->count());
    }

    public function testATierWhoseEveryProviderIsOutIsNoProviderLeftBeforeAnyCall(): void
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturn(30);
        $never = static fn (): never => throw new RuntimeException('never sent');

        try {
            $this->substitutedVendor($never, $never, $cooldowns)->review('alpha', Tier::Strong, $this->request());
            self::fail('A tier whose every provider is out was called.');
        } catch (NoProviderLeft $none) {
            self::assertSame('Every provider of the tier is out: claude_code for 30 s, openai for 30 s.', $none->getMessage());
        }
    }

    public function testABlindOwnProviderThatAnswersEveryRoundCannotApprove(): void
    {
        $approve = static fn (): string => VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('findings', [])->json();

        $review = $this->substitutedVendor($approve, $approve, new NoCooldowns())->review('alpha', Tier::Strong, $this->request());

        self::assertNull($review->substitute);
        self::assertSame(Verdict::UNREAD_APPROVAL, $review->verdict->findings[0]->id);
    }

    public function testARoundWhoseUsageCannotBeReadBeforeAFailureIsLoggedAndTheFailureStillRecorded(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willThrowException(new JsonException('Syntax error'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();

        new UsageLedger($ledger, new MockClock(self::NOW), $logger)->leaveUnsettled(new UsageReservation('a call'), new ServerException(503), new DeferredResult($converter, new InMemoryRawResult()));

        self::assertSame(['unsettled', ServerException::class, []], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['failure'], $ledger->payloads()[0]['usages']]);
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('warning', $logs[0][0]);
        self::assertInstanceOf(JsonException::class, $logs[0][2]['exception']);
    }

    public function testARateLimitedCallIsATypedErrorWithTheResetTheProviderSent(): void
    {
        $ledger = new InMemoryLedger();
        $limited = static fn (): never => throw new RateLimitExceededException(30);

        try {
            $this->vendor($limited, $ledger)->review('alpha', Tier::Strong, $this->request());
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(30, $rateLimited->retryAfterSeconds);
            self::assertEquals(new MockClock('2026-10-27 12:00:30.000 UTC')->now(), $rateLimited->resetsAt(new MockClock(self::NOW)->now()));
            self::assertInstanceOf(RateLimitExceededException::class, $rateLimited->getPrevious());
        }

        self::assertSame(RateLimitExceededException::class, $ledger->payloads()[1]['failure']);
    }

    public function testAProviderWhoseEveryAccountCoolsDownIsARateLimitUntilTheFirstAccountIsFree(): void
    {
        $out = static fn (): never => throw new EveryAccountCoolingDown(Provider::Anthropic, 45);

        try {
            $this->vendor($out)->review('alpha', Tier::Strong, $this->request());
            self::fail('A provider whose every account is out must be a typed rate limit.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(45, $rateLimited->retryAfterSeconds);
            self::assertInstanceOf(EveryAccountCoolingDown::class, $rateLimited->getPrevious());
        }
    }

    public function testARejectedKeyIsATypedErrorWithoutTheProvidersText(): void
    {
        $revoked = static fn (): never => throw new AuthenticationException('invalid x-api-key: sk-secret');

        try {
            $this->vendor($revoked)->review('alpha', Tier::Strong, $this->request());
            self::fail('A rejected key must be a typed error.');
        } catch (ProviderRejectedTheKey $rejected) {
            self::assertStringNotContainsString('sk-secret', $rejected->getMessage());
            self::assertInstanceOf(AuthenticationException::class, $rejected->getPrevious());
        }
    }

    public function testAUsageRowNamesTheAccountItsRoundRanOn(): void
    {
        $ledger = new InMemoryLedger();
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json());

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request());

        /** @var list<array<string, mixed>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertArrayHasKey('account', $usages[0]);
        self::assertNull($usages[0]['account']);
    }

    public function testARateLimitWithoutAResetHasNone(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException();

        try {
            $this->vendor($limited)->review('alpha', Tier::Strong, $this->request());
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertNull($rateLimited->resetsAt(new MockClock(self::NOW)->now()));
        }
    }

    public function testANegativeResetFromTheProviderResetsNowInsteadOfInThePast(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException(-5);

        try {
            $this->vendor($limited)->review('alpha', Tier::Strong, $this->request());
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(0, $rateLimited->retryAfterSeconds);
            self::assertEquals(new MockClock(self::NOW)->now(), $rateLimited->resetsAt(new MockClock(self::NOW)->now()));
        }
    }

    public function testAHugeResetFromTheProviderIsCappedAtOneDay(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException(PHP_INT_MAX);

        try {
            $this->vendor($limited)->review('alpha', Tier::Strong, $this->request());
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(86400, $rateLimited->retryAfterSeconds);
            self::assertEquals(new MockClock('2026-10-28 12:00:00.000 UTC')->now(), $rateLimited->resetsAt(new MockClock(self::NOW)->now()));
        }
    }

    public function testAnotherTransientProviderErrorIsATypedError(): void
    {
        $down = static fn (): never => throw new ServerException(503);

        $this->expectException(ProviderFailedTransiently::class);

        $this->vendor($down)->review('alpha', Tier::Strong, $this->request());
    }

    public function testAnAnswerWithoutUsageSettlesWithNoTokens(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertSame([], $ledger->payloads()[1]['usages']);
    }

    public function testAnAnswerThatIsNotAVerdictStillSettlesWithTheTokensItUsed(): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor(static fn (): TextResult => self::withUsage('not a verdict'), $ledger)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([100, 20], [$usages[0]['prompt'], $usages[0]['completion']]);
    }

    public function testAProviderAnswerThatFailsToMapSettlesWithTheTokensItsExtractorReads(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturn(new TokenUsage(promptTokens: 9, model: 'big'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RuntimeException('not a verdict'));
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $result = new DeferredResult($converter, new InMemoryRawResult());
        $ledger = new InMemoryLedger();

        new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger())->settle(new UsageReservation('a call'), $result);

        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[0]['usages'];
        self::assertSame(9, $usages[0]['prompt']);
    }

    public function testAnAnswerWhoseUsageCannotBeReadKeepsItsVerdictAndIsLeftUnsettled(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willThrowException(new JsonException('Syntax error'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new UnexpectedResultTypeException(ObjectResult::class, TextResult::class));
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturn(new ResolvedModel(Provider::Anthropic, 'big'));
        $tiers->method('invoke')->willReturn(new DeferredResult($converter, new InMemoryRawResult()));
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'big'));
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();
        $vendor = new SymfonyAiVendor($tiers, $resolver, $this->reviewer(), new UsageLedger($ledger, new MockClock(self::NOW), $logger), $this->limit);

        $verdict = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        [$started, $unsettled] = $ledger->payloads();
        self::assertSame(2, $ledger->count());
        self::assertSame(['started', 'unsettled', JsonException::class], [$started['phase'], $unsettled['phase'], $unsettled['failure']]);
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('warning', $logs[0][0]);
        self::assertInstanceOf(JsonException::class, $logs[0][2]['exception']);
    }

    public function testAnAggregatedUsageSettlesAsOneEntryPerPart(): void
    {
        $ledger = new InMemoryLedger();
        $aggregation = new TokenUsageAggregation([new TokenUsage(promptTokens: 7, model: 'big'), new TokenUsage(promptTokens: 3, cachedTokens: 2, model: 'small')]);
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json(), $aggregation);

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request());

        /** @var list<array<string, int|string|null>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([['big', 7, null], ['small', 3, 2]], array_map(static fn (array $usage): array => [$usage['model'], $usage['prompt'], $usage['cached']], $usages));
    }

    public function testACallWhoseStartTheLedgerRefusesIsNeverSent(): void
    {
        $sent = false;
        $answer = static function () use (&$sent): string {
            $sent = true;

            return VerdictDocument::fromTheDesign()->json();
        };

        try {
            $this->vendor($answer, new InMemoryLedger(accepted: 0))->review('alpha', Tier::Strong, $this->request());
            self::fail('A call that cannot be reserved must not run.');
        } catch (UsageNotRecorded) {
            // Expected, because an unreserved call would spend tokens no row accounts for.
        }

        self::assertFalse($sent);
    }

    public function testASettlementTheLedgerRefusesFailsTheCallAndLogsItsTokens(): void
    {
        $logger = new BufferingLogger();
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json());

        try {
            $this->vendor($answer, new InMemoryLedger(accepted: 1), $logger)->review('alpha', Tier::Strong, $this->request());
            self::fail('A call whose usage is lost must not pass as answered.');
        } catch (UsageNotRecorded $refused) {
            self::assertStringContainsString('"prompt":100', $refused->getMessage());
        }

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        [[$level, , $context]] = $logs;
        self::assertSame('error', $level);
        self::assertIsString($context['usage']);
        self::assertStringContainsString('"phase":"settled"', $context['usage']);
        self::assertStringContainsString('"prompt":100', $context['usage']);
    }

    public function testAnUnsettledRowTheLedgerRefusesKeepsTheCallFailureAsItsCause(): void
    {
        $failure = new RuntimeException('provider down');

        try {
            $this->vendor(static fn (): never => throw $failure, new InMemoryLedger(accepted: 1))->review('alpha', Tier::Strong, $this->request());
            self::fail('A call whose usage is lost must fail.');
        } catch (UsageNotRecorded $refused) {
            self::assertSame($failure, $refused->getPrevious());
        }
    }

    public function testTheCallIdTakesItsMomentFromTheClock(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), $ledger)->review('alpha', Tier::Strong, $this->request());

        /** @var string $call */
        $call = $ledger->payloads()[0]['call'];
        self::assertEquals(new MockClock(self::NOW)->now(), UuidV7::fromString($call)->getDateTime());
    }

    #[Group('persistence')]
    public function testTheContainerWiresTheVendorToTheTierPlatform(): void
    {
        $container = self::getContainer();
        $answer = static fn (Model $model): string => VerdictDocument::fromTheDesign()->with('follow_ups', [$model->getName()])->json();
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in($container)->dispatcher);
        $container->set(TierResolver::class, new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
        ], []));
        $container->set(Ledger::class, new InMemoryLedger());
        $container->set(TierPlatform::PLATFORMS, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]));
        $vendor = $container->get(SymfonyAiVendor::class);
        self::assertInstanceOf(SymfonyAiVendor::class, $vendor);
        $conversations = $container->get(MessageStoreInterface::class);
        self::assertInstanceOf(ManagedMessageStoreInterface::class, $conversations);
        self::assertInstanceOf(MessageStoreInterface::class, $conversations);
        EmptyDatabase::in($container);
        $conversations->setup();
        $request = $this->request();

        $verdict = $vendor->review('alpha', Tier::Strong, $request)->verdict;

        self::assertCount(4, $conversations->load($request->conversation->id));
        self::assertSame(['big'], $verdict->follow_ups);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertCount(1, $verdict->findings);
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief('Review the change.', [$this->openFinding('F1'), $this->openFinding('F2')]), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }

    private function reviewer(): ReviewerAgent
    {
        return new ReviewerAgent(new ReviewerPrompt(new Fence(), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new InMemoryMessageStore());
    }

    private static function withUsage(string $json, TokenUsageInterface $usage = new TokenUsage(promptTokens: 100, completionTokens: 20, cachedTokens: 60, remainingTokens: 5000, totalTokens: 120, model: 'big')): TextResult
    {
        return self::carrying(new TextResult($json), $usage);
    }

    /**
     * @template T of ResultInterface
     *
     * @param T $result
     *
     * @return T
     */
    private static function carrying(ResultInterface $result, TokenUsageInterface $usage): ResultInterface
    {
        $result->getMetadata()->add('token_usage', $usage);

        return $result;
    }

    private function vendor(Closure $answer, InMemoryLedger $ledger = new InMemoryLedger(), LoggerInterface $logger = new NullLogger()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock(self::NOW), $logger);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer(), $usage, $this->limit);
    }

    // The tier's own provider is the subscription CLI, which cannot read the checkout, and its one substitute reads it.
    private function substitutedVendor(Closure $own, Closure $substitute, AccountCooldowns $cooldowns): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'own', 'substitutes' => [['provider' => 'openai', 'model' => 'sub']]]]],
        ], []);
        $dispatcher = ScriptedReviewer::in(self::getContainer())->dispatcher;
        $platforms = new ServiceLocator([
            Provider::ClaudeCode->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform($own, eventDispatcher: $dispatcher),
            Provider::OpenAi->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform($substitute, eventDispatcher: $dispatcher),
        ]);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, $this->reviewer(), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger()), $this->limit);
    }
}
