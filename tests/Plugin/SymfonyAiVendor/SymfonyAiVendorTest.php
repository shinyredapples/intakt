<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
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
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;
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
#[UsesClass(Tier::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(Kernel::class)]
final class SymfonyAiVendorTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00.000 UTC';

    public function testAStructuredCallReturnsTheVerdictTheModelAnswered(): void
    {
        $vendor = $this->vendorAnswering(VerdictDocument::fromTheDesign()->json());

        $verdict = $vendor->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertCount(1, $verdict->findings);
    }

    public function testTheTierSelectsTheModelTheCallIsSentTo(): void
    {
        $answer = static fn (Model $model): string => VerdictDocument::fromTheDesign()->with('follow_ups', [$model->getName()])->json();

        $verdict = $this->vendor($answer)->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame(['big'], $verdict->follow_ups);
    }

    public function testAnAnswerThatIsNotAVerdictCountsAsChanges(): void
    {
        $vendor = $this->vendorAnswering('not a verdict');

        $verdict = $vendor->review('alpha', Tier::Strong, 'Review the change.');

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

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, 'Review the change.');

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
            $this->vendor($failing, $ledger)->review('alpha', Tier::Strong, 'Review the change.');
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

    public function testARateLimitedCallIsATypedErrorWithTheResetTheProviderSent(): void
    {
        $ledger = new InMemoryLedger();
        $limited = static fn (): never => throw new RateLimitExceededException(30);

        try {
            $this->vendor($limited, $ledger)->review('alpha', Tier::Strong, 'Review the change.');
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(30, $rateLimited->retryAfterSeconds);
            self::assertEquals(new MockClock('2026-10-27 12:00:30.000 UTC')->now(), $rateLimited->resetsAt(new MockClock(self::NOW)->now()));
            self::assertInstanceOf(RateLimitExceededException::class, $rateLimited->getPrevious());
        }

        self::assertSame(RateLimitExceededException::class, $ledger->payloads()[1]['failure']);
    }

    public function testARateLimitWithoutAResetHasNone(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException();

        try {
            $this->vendor($limited)->review('alpha', Tier::Strong, 'Review the change.');
            self::fail('A rate limit must be a typed error.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertNull($rateLimited->resetsAt(new MockClock(self::NOW)->now()));
        }
    }

    public function testANegativeResetFromTheProviderResetsNowInsteadOfInThePast(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException(-5);

        try {
            $this->vendor($limited)->review('alpha', Tier::Strong, 'Review the change.');
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
            $this->vendor($limited)->review('alpha', Tier::Strong, 'Review the change.');
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

        $this->vendor($down)->review('alpha', Tier::Strong, 'Review the change.');
    }

    public function testAnAnswerWithoutUsageSettlesWithNoTokens(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), $ledger)->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame([], $ledger->payloads()[1]['usages']);
    }

    public function testAnAnswerThatIsNotAVerdictStillSettlesWithTheTokensItUsed(): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor(static fn (): TextResult => self::withUsage('not a verdict'), $ledger)->review('alpha', Tier::Strong, 'Review the change.');

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
        $tiers->method('invoke')->willReturn(new DeferredResult($converter, new InMemoryRawResult()));
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();
        $vendor = new SymfonyAiVendor($tiers, new VerdictParser(), new UsageLedger($ledger, new MockClock(self::NOW), $logger));

        $verdict = $vendor->review('alpha', Tier::Strong, 'Review the change.');

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

        $this->vendor($answer, $ledger)->review('alpha', Tier::Strong, 'Review the change.');

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
            $this->vendor($answer, new InMemoryLedger(accepted: 0))->review('alpha', Tier::Strong, 'Review the change.');
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
            $this->vendor($answer, new InMemoryLedger(accepted: 1), $logger)->review('alpha', Tier::Strong, 'Review the change.');
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
            $this->vendor(static fn (): never => throw $failure, new InMemoryLedger(accepted: 1))->review('alpha', Tier::Strong, 'Review the change.');
            self::fail('A call whose usage is lost must fail.');
        } catch (UsageNotRecorded $refused) {
            self::assertSame($failure, $refused->getPrevious());
        }
    }

    public function testTheCallIdTakesItsMomentFromTheClock(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), $ledger)->review('alpha', Tier::Strong, 'Review the change.');

        /** @var string $call */
        $call = $ledger->payloads()[0]['call'];
        self::assertEquals(new MockClock(self::NOW)->now(), UuidV7::fromString($call)->getDateTime());
    }

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

        $verdict = $vendor->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame(['big'], $verdict->follow_ups);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertCount(1, $verdict->findings);
    }

    private static function withUsage(string $json, TokenUsageInterface $usage = new TokenUsage(promptTokens: 100, completionTokens: 20, cachedTokens: 60, remainingTokens: 5000, totalTokens: 120, model: 'big')): TextResult
    {
        $result = new TextResult($json);
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

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms), new VerdictParser(), $usage);
    }
}
