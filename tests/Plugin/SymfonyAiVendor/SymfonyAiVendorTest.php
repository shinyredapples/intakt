<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Generator;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderOutage;
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
use Intakt\Core\Ledger\Contract\LedgerPayloads;
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
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\FinalStreamedUsage;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewServerHttpClient;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamedAnswer;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Core\Review\Infrastructure\ToolsOfRound;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
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
use Intakt\Tests\Core\Mcp\Contract\KernelHttpClient;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\ManagedMessageStoreInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

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
#[UsesClass(EveryKeyRejected::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(ProviderOutage::class)]
#[UsesClass(NoProviderLeft::class)]
#[UsesClass(ProviderRejectedTheKey::class)]
#[UsesClass(Tier::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictUnreadable::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictStreamListener::class)]
#[UsesClass(StreamedAnswer::class)]
#[UsesClass(AccountLimitedVendor::class)]
#[UsesClass(FinalStreamedUsage::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(StreamingFallbacks::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReviewerMcpToolboxFactory::class)]
#[UsesClass(ReviewServerHttpClient::class)]
#[UsesClass(ToolsOfRound::class)]
#[UsesClass(ReviewCredential::class)]
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
#[UsesClass(VendorAccountBusy::class)]
#[UsesClass(VendorReview::class)]
#[UsesClass(Kernel::class)]
final class SymfonyAiVendorTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00.000 UTC';

    private const int OUTPUT_LIMIT = 16000;

    // A kernel of its own per test, so no listener carries what an earlier test left; only its dispatcher is used, and the persistence test boots its own.
    private KernelInterface $platformKernel;

    private VendorAccountLimit $limit;

    private EventDispatcherInterface $dispatcher;

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

    public function testEveryReviewerOfTheSeededTiersEndsWithAVerdictAndItsTiersOutputLimitOnItsCall(): void
    {
        /** @var array<string, mixed> $limits */
        $limits = [];
        $answer = static function (Model $model, array|string|object $input, array $options) use (&$limits): string {
            $limits[$model->getName()] = $options[PlatformOptions::MAX_OUTPUT_TOKENS] ?? null;

            return VerdictDocument::fromTheDesign()->json();
        };
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => [
                'strong' => ['provider' => 'anthropic', 'model' => 'big', 'max_output' => 16000],
                'standard' => ['provider' => 'anthropic', 'model' => 'middle', 'max_output' => 8000],
            ]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

        $correctness = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;
        $security = $vendor->review('alpha', Tier::Standard, $this->request())->verdict;

        self::assertSame(Decision::Changes, $correctness->verdict);
        self::assertSame(Decision::Changes, $security->verdict);
        self::assertSame(['F1', 'F2'], $security->resolved);
        self::assertSame(['big' => 16000, 'middle' => 8000], $limits);
    }

    public function testACallStoppedByTheOutputLimitIsRetriedOnceWithTwiceTheRoomAndGivesItsVerdict(): void
    {
        $ledger = new InMemoryLedger();
        /** @var list<mixed> $limits */
        $limits = [];
        $answer = static function (Model $model, array|string|object $input, array $options) use (&$limits): string {
            $limits[] = $options[PlatformOptions::MAX_OUTPUT_TOKENS] ?? null;

            return 1 === count($limits) ? throw new MaxOutputTokensException('Truncated at the output limit.') : VerdictDocument::fromTheDesign()->json();
        };

        $verdict = $this->vendorWithOutputLimit($answer, $ledger)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame([16000, 32000], $limits);
        self::assertSame(['started', 'unsettled', 'started', 'settled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(MaxOutputTokensException::class, $ledger->payloads()[1]['failure']);
    }

    public function testTheRetryOfACallStoppedByTheOutputLimitIsReservedUnderTheSameModel(): void
    {
        $ledger = new InMemoryLedger();
        $calls = 0;
        $answer = static function () use (&$calls): string {
            return 1 === ++$calls ? throw new MaxOutputTokensException('Truncated at the output limit.') : VerdictDocument::fromTheDesign()->json();
        };

        $this->vendorWithOutputLimit($answer, $ledger)->review('alpha', Tier::Strong, $this->request());

        $started = array_values(array_filter($ledger->payloads(), static fn (array $payload): bool => 'started' === $payload['phase']));
        self::assertSame(['big', 'big'], array_column($started, 'model'));
    }

    public function testACallStoppedByTheOutputLimitAgainOnItsRetryFailsTheReviewer(): void
    {
        $ledger = new InMemoryLedger();
        $calls = 0;
        $answer = static function () use (&$calls): never {
            ++$calls;

            throw new MaxOutputTokensException('Truncated at the output limit.');
        };

        try {
            $this->vendorWithOutputLimit($answer, $ledger)->review('alpha', Tier::Strong, $this->request());
            self::fail('A reviewer stopped by the limit twice must fail.');
        } catch (MaxOutputTokensException) {
            // Expected, because the reviewer that cannot fit its verdict in the bigger room counts as failed.
        }

        self::assertSame(2, $calls);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
    }

    public function testTheLiveTextOfARetryIsReportedUnderTheReviewerTheLedgerRecords(): void
    {
        $calls = 0;
        $answer = static function () use (&$calls): StreamResult {
            return 1 === ++$calls ? throw new MaxOutputTokensException('Truncated at the output limit.') : new StreamResult((static function (): Generator { yield new TextDelta(VerdictDocument::fromTheDesign()->json()); })());
        };
        $updates = new RecordedRoundUpdates();
        $reviewerId = new UuidV7();
        $request = new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')], 1), new ReviewerConversation('You review for correctness.', new UuidV7(), new UuidV7(), $reviewerId));

        $verdict = $this->vendorWithOutputLimit($answer, new InMemoryLedger(), $updates)->review('alpha', Tier::Strong, $request)->verdict;

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(2, $calls);
        self::assertNotSame([], $updates->seen);
        self::assertSame([], array_filter($updates->seen, static fn (string $update): bool => !str_contains($update, $reviewerId->toRfc4122())));
    }

    public function testAStreamingFallbackNamesTheLedgersReviewerIdWhenItIsSet(): void
    {
        $ledger = new InMemoryLedger();
        $round = new UuidV7();
        $conversation = new UuidV7();
        $reviewerId = new UuidV7();

        new StreamingFallbacks($ledger, new NullLogger())->record(new ReviewerConversation('You review.', $conversation, $round, $reviewerId));

        self::assertSame([['round' => $round->toRfc4122(), 'reviewer' => $reviewerId->toRfc4122()]], $ledger->payloads());
    }

    public function testAStreamingFallbackNamesTheConversationWhenNoReviewerIdIsSet(): void
    {
        $ledger = new InMemoryLedger();
        $round = new UuidV7();
        $conversation = new UuidV7();

        new StreamingFallbacks($ledger, new NullLogger())->record(new ReviewerConversation('You review.', $conversation, $round));

        self::assertSame([['round' => $round->toRfc4122(), 'reviewer' => $conversation->toRfc4122()]], $ledger->payloads());
    }

    public function testARetryOnATierWithoutAnOutputLimitGetsTwiceTheTiersDefault(): void
    {
        /** @var list<mixed> $limits */
        $limits = [];
        $answer = static function (Model $model, array|string|object $input, array $options) use (&$limits): string {
            $limits[] = $options[PlatformOptions::MAX_OUTPUT_TOKENS] ?? null;

            return 1 === count($limits) ? throw new MaxOutputTokensException('Truncated at the model default.') : VerdictDocument::fromTheDesign()->json();
        };

        $this->vendor($answer)->review('alpha', Tier::Strong, $this->request());

        self::assertSame([null, 2 * Tier::Strong->defaultMaxOutput()], $limits);
    }

    public function testAnAnswerThatIsNotAVerdictOnTheRetryTooIsRefusedAsUnreadable(): void
    {
        $vendor = $this->vendorAnswering('not a verdict');

        $this->expectException(VerdictUnreadable::class);

        $vendor->review('alpha', Tier::Strong, $this->request());
    }

    public function testAnAnswerThatIsNotAVerdictIsRetriedOnceAndTheReadableRetryGivesTheVerdict(): void
    {
        $answers = ['not a verdict', VerdictDocument::fromTheDesign()->json()];
        $answer = static function () use (&$answers): string {
            return (string) array_shift($answers);
        };

        $verdict = $this->vendor($answer)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([], $answers);
        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
    }

    public function testTheRetryAfterAnUnreadableAnswerGetsTwiceTheOutputRoomInANewConversation(): void
    {
        /** @var list<mixed> $limits */
        $limits = [];
        $answers = ['not a verdict', VerdictDocument::fromTheDesign()->json()];
        $answer = static function (Model $model, array|string|object $input, array $options) use (&$limits, &$answers): string {
            $limits[] = $options[PlatformOptions::MAX_OUTPUT_TOKENS];

            return (string) array_shift($answers);
        };
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big', 'max_output' => self::OUTPUT_LIMIT]]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ConversationsSeen($this->reviewer());
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
        $request = $this->request();

        $vendor->review('alpha', Tier::Strong, $request);

        self::assertSame([self::OUTPUT_LIMIT, 2 * self::OUTPUT_LIMIT], $limits);
        self::assertCount(2, $reviewer->ids);
        self::assertSame($request->conversation->id->toRfc4122(), $reviewer->ids[0]);
        self::assertNotSame($reviewer->ids[0], $reviewer->ids[1]);
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

    public function testAStreamedAnswerGivesTheVerdictAndRecordsTheUsageOnceAsTheSameAnswerUnstreamed(): void
    {
        $answer = VerdictDocument::fromTheDesign()->json();
        $unstreamedLedger = new InMemoryLedger();
        $streamedLedger = new InMemoryLedger();

        $refusesToStream = static fn (mixed $model, mixed $input, array $options): TextResult => true === ($options['stream'] ?? false) ? throw new BadRequestException('This account cannot stream.') : self::withUsage($answer);
        $streamsWhenAsked = static fn (mixed $model, mixed $input, array $options): ResultInterface => true === ($options['stream'] ?? false) ? self::streamed($answer) : self::withUsage($answer);

        $unstreamed = $this->vendor($refusesToStream, $unstreamedLedger)->review('alpha', Tier::Strong, $this->request())->verdict;
        $streamed = $this->vendor($streamsWhenAsked, $streamedLedger)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertEquals($unstreamed, $streamed);
        self::assertSame(['started', 'settled'], array_column($streamedLedger->payloads(), 'phase'));
        self::assertSame($unstreamedLedger->payloads()[1]['usages'], $streamedLedger->payloads()[1]['usages']);
    }

    public function testAStreamCutMidTextAfterAToolRoundLeavesOneUnsettledRowWithBothRoundsAndIsTriedOnceMore(): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor($this->toolRoundThen($this->cutMidText(...)), $ledger)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertSame(Severity::P1, $verdict->findings[0]->severity);
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame($ledger->payloads()[0]['call'], $ledger->payloads()[1]['call']);
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([110, 120], array_column($usages, 'prompt'));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function midStreamOutages(): iterable
    {
        yield 'a server error' => [new ServerException(503)];
        yield 'a server error event' => [new ServerException(errorMessage: 'Overloaded')];
        yield 'a rate limit' => [new RateLimitExceededException(30)];
    }

    #[DataProvider('midStreamOutages')]
    public function testEveryOutageMidStreamAfterAToolRoundBlocksTheReviewWithoutARetryAndLeavesOneUnsettledRowWithBothRounds(Throwable $outage): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor($this->toolRoundThen($this->brokenMidText($outage)), $ledger)->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(StreamEndedEarly::class, $ledger->payloads()[1]['failure']);
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[1]['usages'];
        self::assertSame([110, 120], array_column($usages, 'prompt'));
    }

    public function testAServerErrorMidStreamGivesTheReasonThatTheProviderFailedAfterTheAnswerStarted(): void
    {
        $verdict = $this->vendor($this->toolRoundThen($this->brokenMidText(new ServerException(503))))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertStringContainsString('The provider failed after the answer had started streaming.', $verdict->findings[0]->text);
        self::assertSame(Severity::P1, $verdict->findings[0]->severity);
    }

    public function testAServerErrorMidStreamReachesTheRoundAsTheBlockedVerdictThroughTheAccountLimitedVendor(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $vendor = new AccountLimitedVendor($this->vendor($this->toolRoundThen($this->brokenMidText(new ServerException(503)))), $resolver, new NoCooldowns());

        $verdict = $vendor->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Blocked, Verdict::INCOMPLETE_STREAM], [$verdict->verdict, $verdict->findings[0]->id]);
    }

    public function testAServerErrorBeforeTheStreamsFirstAnswerDeltaStillFailsTheReviewTransientlySoAnotherAccountCanTakeIt(): void
    {
        $ledger = new InMemoryLedger();
        $beforeAnyDelta = static function (): Generator {
            yield new TokenUsage(
                promptTokens: 120,
                model: 'big',
            );

            throw new ServerException(errorMessage: 'Overloaded');
        };

        $failure = $this->thrownBy(fn (): VendorReview => $this->vendor($this->toolRoundThen($beforeAnyDelta), $ledger)->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderFailedTransiently::class, $failure);
        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(ServerException::class, $ledger->payloads()[1]['failure']);
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
                return self::carrying(new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]), new TokenUsage(
                    promptTokens: 100,
                    model: 'big',
                ));
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
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

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

    public function testAReviewOnATierWhoseOwnProviderIsDisabledIsAnsweredByTheSubstituteAndMarkedAsOne(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'own', 'substitutes' => [['provider' => 'openai', 'model' => 'sub']]]]]], ['claude_code' => 'disabled']);
        $answer = MockPlatformFactory::createPlatform(VerdictDocument::fromTheDesign()->json(), eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::OpenAi->value => static fn (): PlatformInterface => $answer]);
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

        $review = $vendor->review('alpha', Tier::Strong, $this->request());

        self::assertSame(Provider::OpenAi, $review->substitute);
    }

    public function testTheStartedRowOfAReviewNamesTheModelItsTierResolvedTo(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json()), $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertSame('big', $ledger->payloads()[0]['model']);
    }

    public function testTheStartedRowOfAReviewNamesTheReviewerOfItsConversation(): void
    {
        $ledger = new InMemoryLedger();
        $reviewerId = new UuidV7();
        $request = new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')], 1), new ReviewerConversation('You review for correctness.', new UuidV7(), new UuidV7(), $reviewerId));

        $this->vendor(static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json()), $ledger)->review('alpha', Tier::Strong, $request);

        self::assertSame(['started', $reviewerId->toRfc4122()], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['reviewer'] ?? null]);
    }

    public function testTheStartedRowOfAReviewWithoutAReviewerIdNamesNoReviewer(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json()), $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertArrayNotHasKey('reviewer', $ledger->payloads()[0]);
    }

    public function testTheStartedRowOfAReviewAnsweredByASubstituteNamesTheSubstitutesModel(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'own', 'substitutes' => [['provider' => 'openai', 'model' => 'sub']]]]]], ['claude_code' => 'disabled']);
        $answer = MockPlatformFactory::createPlatform(VerdictDocument::fromTheDesign()->json(), eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::OpenAi->value => static fn (): PlatformInterface => $answer]);
        $ledger = new InMemoryLedger();
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

        $vendor->review('alpha', Tier::Strong, $this->request());

        self::assertSame('sub', $ledger->payloads()[0]['model']);
    }

    public function testATierWithoutSubstitutesWhoseEveryKeyWasRejectedIsARejectedKeyWithoutReservingUsage(): void
    {
        $ledger = new InMemoryLedger();
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isEveryAccountRejected')->willReturn(true);
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform('never sent')]);
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

        try {
            $vendor->review('alpha', Tier::Strong, $this->request());
            self::fail('A provider whose every key was rejected was called.');
        } catch (ProviderRejectedTheKey $rejected) {
            self::assertInstanceOf(EveryKeyRejected::class, $rejected->getPrevious());
        }

        self::assertSame(0, $ledger->count());
    }

    public function testABlindOwnProviderThatAnswersEveryRoundCannotApprove(): void
    {
        $approve = static fn (): string => VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('findings', [])->json();

        $review = $this->substitutedVendor($approve, $approve, new NoCooldowns())->review('alpha', Tier::Strong, $this->request());

        self::assertNull($review->substitute);
        self::assertSame(Verdict::UNREAD_APPROVAL, $review->verdict->findings[0]->id);
    }

    public function testABlindOwnProviderCannotApproveThroughChangesOfOnlyP3Findings(): void
    {
        $changes = static fn (): string => VerdictDocument::fromTheDesign()->withFinding('severity', 'P3')->json();

        $review = $this->substitutedVendor($changes, $changes, new NoCooldowns())->review('alpha', Tier::Strong, $this->request());

        self::assertSame([Decision::Blocked, Verdict::UNREAD_APPROVAL], [$review->verdict->verdict, $review->verdict->findings[0]->id]);
    }

    public function testABlindOwnProviderCannotApproveThroughAP2TheSecondRoundLowersToP3(): void
    {
        $changes = static fn (): string => VerdictDocument::fromTheDesign()->withFinding('severity', 'P2')->withFinding('requirement', null)->json();

        $review = $this->substitutedVendor($changes, $changes, new NoCooldowns())->review('alpha', Tier::Strong, $this->requestInRound(2));

        self::assertSame([Decision::Blocked, Verdict::UNREAD_APPROVAL], [$review->verdict->verdict, $review->verdict->findings[0]->id]);
    }

    public function testABlindOwnProviderAskingForChangesWithAP2ThatNamesARequirementKeepsItsVerdictInTheSecondRound(): void
    {
        $changes = static fn (): string => VerdictDocument::fromTheDesign()->withFinding('severity', 'P2')->json();

        $review = $this->substitutedVendor($changes, $changes, new NoCooldowns())->review('alpha', Tier::Strong, $this->requestInRound(2));

        self::assertSame(Decision::Changes, $review->verdict->verdict);
    }

    public function testABlindOwnProviderAskingForChangesWithAP2WithoutARequirementKeepsItsVerdictInTheFirstRound(): void
    {
        $changes = static fn (): string => VerdictDocument::fromTheDesign()->withFinding('severity', 'P2')->withFinding('requirement', null)->json();

        $review = $this->substitutedVendor($changes, $changes, new NoCooldowns())->review('alpha', Tier::Strong, $this->requestInRound(1));

        self::assertSame(Decision::Changes, $review->verdict->verdict);
    }

    public function testABlindOwnProviderAskingForChangesWithAP1KeepsItsVerdict(): void
    {
        $changes = static fn (): string => VerdictDocument::fromTheDesign()->json();

        $review = $this->substitutedVendor($changes, $changes, new NoCooldowns())->review('alpha', Tier::Strong, $this->request());

        self::assertSame(Decision::Changes, $review->verdict->verdict);
    }

    public function testARoundWhoseUsageCannotBeReadBeforeAFailureIsLoggedAndTheFailureStillRecorded(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willThrowException(new JsonException('Syntax error'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();

        new UsageLedger($ledger, new MockClock(self::NOW), $logger, StoredSerializerFactory::create())->leaveUnsettled(new UsageReservation('a call'), new ServerException(503), new DeferredResult($converter, new InMemoryRawResult()));

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

    public function testEachAnswerThatIsNotAVerdictStillSettlesWithTheTokensItUsed(): void
    {
        $ledger = new InMemoryLedger();
        $vendor = $this->vendor(static fn (): TextResult => self::withUsage('not a verdict'), $ledger);

        RefusedAsUnreadable::after(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        $payloads = $ledger->payloads();
        self::assertSame(['started', 'settled', 'started', 'settled'], array_column($payloads, 'phase'));
        /** @var array{0: array{usages: list<array<string, int>>}, 1: array{usages: list<array<string, int>>}} $settled */
        $settled = [$payloads[1], $payloads[3]];
        self::assertSame([[100, 20], [100, 20]], [[$settled[0]['usages'][0]['prompt'], $settled[0]['usages'][0]['completion']], [$settled[1]['usages'][0]['prompt'], $settled[1]['usages'][0]['completion']]]);
    }

    public function testAProviderAnswerThatFailsToMapSettlesWithTheTokensItsExtractorReads(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturn(new TokenUsage(
            promptTokens: 9,
            model: 'big',
        ));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new PlatformRuntimeException('not a verdict'));
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $result = new DeferredResult($converter, new InMemoryRawResult());
        $ledger = new InMemoryLedger();

        new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create())->settle(new UsageReservation('a call'), $result);

        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[0]['usages'];
        self::assertSame(9, $usages[0]['prompt']);
    }

    public function testEachAnswerWhoseUsageCannotBeReadIsLeftUnsettledWithAWarning(): void
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
        $resolver->method('configured')->willReturn(new ResolvedModel(Provider::Anthropic, 'big'));
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();
        $vendor = new SymfonyAiVendor($tiers, $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($ledger, new MockClock(self::NOW), $logger, StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));

        RefusedAsUnreadable::after(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        $payloads = $ledger->payloads();
        self::assertSame(['started', 'unsettled', 'started', 'unsettled'], array_column($payloads, 'phase'));
        self::assertSame([JsonException::class, JsonException::class], [$payloads[1]['failure'], $payloads[3]['failure']]);
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('warning', $logs[0][0]);
        self::assertInstanceOf(JsonException::class, $logs[0][2]['exception']);
    }

    public function testAnAggregatedUsageSettlesAsOneEntryPerPart(): void
    {
        $ledger = new InMemoryLedger();
        $aggregation = new TokenUsageAggregation([new TokenUsage(
            promptTokens: 7,
            model: 'big',
        ), new TokenUsage(
            promptTokens: 3,
            cachedTokens: 2,
            model: 'small',
        )]);
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
        // A second kernel serves the review server, so the reviewer lists its tools without a web server; not this one, which resets its services before each request it handles.
        $server = new Kernel('test', false);
        $server->boot();
        $credential = $container->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);
        $container->set(ReviewServerHttpClient::class, new ReviewServerHttpClient($credential, new KernelHttpClient($server)));
        $answer = static fn (Model $model): string => VerdictDocument::fromTheDesign()->with('follow_ups', [$model->getName()])->json();
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in($container)->dispatcher);
        $container->set(TierResolver::class, new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
        ], []));
        $container->set(Ledger::class, new InMemoryLedger());
        $container->set(AiServices::PROVIDER_PLATFORMS, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]));
        $vendor = $container->get(SymfonyAiVendor::class);
        self::assertInstanceOf(SymfonyAiVendor::class, $vendor);
        $conversations = $container->get(MessageStoreInterface::class);
        self::assertInstanceOf(ManagedMessageStoreInterface::class, $conversations);
        self::assertInstanceOf(MessageStoreInterface::class, $conversations);
        EmptyDatabase::in($container);
        $conversations->setup();
        $request = $this->request(new UuidV7());

        $verdict = $vendor->review('alpha', Tier::Strong, $request)->verdict;
        $server->shutdown();

        self::assertCount(5, $conversations->load($request->conversation->id));
        self::assertSame(['big'], $verdict->follow_ups);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertCount(1, $verdict->findings);
    }

    public function testAReviewerCallMadeForARoundWritesItsRoundIdOnTheStartedRow(): void
    {
        $ledger = new InMemoryLedger();
        $round = new UuidV7();
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json());

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request($round));

        [$started, $settled] = $ledger->payloads();
        self::assertSame($round->toString(), $started['round']);
        self::assertArrayNotHasKey('round', $settled);
    }

    public function testAReviewerCallMadeOutsideARoundWritesNoRoundOnTheStartedRow(): void
    {
        $ledger = new InMemoryLedger();
        $answer = static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json());

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, $this->request());

        self::assertArrayNotHasKey('round', $ledger->payloads()[0]);
    }

    public function testTheServicesResetterForgetsTheListenersAReviewCalled(): void
    {
        self::assertInstanceOf(TraceableEventDispatcher::class, $this->dispatcher);
        // A review calls the listeners the container declares, because the compiled dispatcher takes no listener at runtime since Symfony 8.2.
        $this->vendor(static fn (): TextResult => self::withUsage(VerdictDocument::fromTheDesign()->json()))->review('alpha', Tier::Strong, $this->request());
        self::assertNotSame([], $this->dispatcher->getCalledListeners());

        $resetter = $this->platformKernel->getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame([], $this->dispatcher->getCalledListeners());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $this->platformKernel = self::createKernel();
        $this->platformKernel->boot();
        $this->dispatcher = ScriptedReviewer::in($this->platformKernel->getContainer())->dispatcher;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->platformKernel->shutdown();
        parent::tearDown();
    }

    private function vendorAnswering(string $output): SymfonyAiVendor
    {
        return $this->vendor(static fn (): string => $output);
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

    private function cutMidText(): Generator
    {
        yield new TokenUsage(
            promptTokens: 120,
            model: 'big',
        );
        yield new TextDelta('{"verdict":"approve",');

        throw new IncompleteStreamException('The stream ended before its terminal event.');
    }

    /**
     * @param callable(): Generator<mixed, DeltaInterface, mixed, mixed> $lastRound
     */
    private function toolRoundThen(callable $lastRound): Closure
    {
        /** @var list<callable(): Generator<mixed, DeltaInterface, mixed, mixed>> $rounds */
        $rounds = [$this->toolRound(...), $lastRound];

        // A call after the script is the unstreamed retry of a stream cut without a provider outage, and it is cut again, so the reviewer still ends blocked.
        return static function () use (&$rounds): StreamResult {
            $round = array_shift($rounds) ?? self::cutAgain();

            return new StreamResult($round());
        };
    }

    private function toolRound(): Generator
    {
        yield new ToolCallComplete([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]);
        yield new TokenUsage(
            promptTokens: 110,
            model: 'big',
        );
    }

    /**
     * @return Closure(): Generator<mixed, DeltaInterface, mixed, mixed>
     */
    private function brokenMidText(Throwable $outage): Closure
    {
        return static function () use ($outage): Generator {
            yield new TokenUsage(
                promptTokens: 120,
                model: 'big',
            );
            yield new TextDelta('{"verdict":');

            throw $outage;
        };
    }

    private static function cutAgain(): never
    {
        throw new IncompleteStreamException('The answer ended before it was complete.');
    }

    private function request(?Uuid $round = null): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')], 1), new ReviewerConversation('You review for correctness.', new UuidV7(), $round));
    }

    private function requestInRound(int $round): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$this->openFinding('F1'), $this->openFinding('F2')], $round), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }

    private function reviewer(RecordedRoundUpdates $updates = new RecordedRoundUpdates()): ReviewerAgent
    {
        return new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($updates), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
    }

    private static function withUsage(string $json, TokenUsageInterface $usage = new TokenUsage(
        promptTokens: 100,
        completionTokens: 20,
        cachedTokens: 60,
        remainingTokens: 5000,
        totalTokens: 120,
        model: 'big',
    )): TextResult
    {
        return self::carrying(new TextResult($json), $usage);
    }

    // Two usage events, because a provider reports usage when the stream starts and again, whole, when it ends.
    private static function streamed(string $json): StreamResult
    {
        return new StreamResult((static function () use ($json): Generator {
            yield new TokenUsage(
                promptTokens: 100,
                model: 'big',
            );
            foreach (str_split($json, 40) as $part) {
                yield new TextDelta($part);
            }
            yield new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                cachedTokens: 60,
                remainingTokens: 5000,
                totalTokens: 120,
                model: 'big',
            );
        })());
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
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock(self::NOW), $logger, StoredSerializerFactory::create());

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), $usage, $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }

    private function vendorWithOutputLimit(Closure $answer, InMemoryLedger $ledger, RecordedRoundUpdates $updates = new RecordedRoundUpdates()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big', 'max_output' => self::OUTPUT_LIMIT]]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: $this->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create());

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $this->reviewer($updates), new JudgeAgent(new JudgePrompt(new Fence())), $usage, $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }

    // The tier's own provider is the subscription CLI, which cannot read the checkout, and its one substitute reads it.
    private function substitutedVendor(Closure $own, Closure $substitute, AccountCooldowns $cooldowns): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'own', 'substitutes' => [['provider' => 'openai', 'model' => 'sub']]]]],
        ], []);
        $dispatcher = $this->dispatcher;
        $platforms = new ServiceLocator([
            Provider::ClaudeCode->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform($own, eventDispatcher: $dispatcher),
            Provider::OpenAi->value => static fn (): PlatformInterface => MockPlatformFactory::createPlatform($substitute, eventDispatcher: $dispatcher),
        ]);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $this->limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class));
    }
}
