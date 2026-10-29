<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
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
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(UsageLedger::class)]
#[CoversClass(UsageReservation::class)]
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
        self::assertCount(2, $ledger->events);
        self::assertSame(LedgerEventKind::UsageRecorded, $ledger->events[0]->kind);
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
        self::assertCount(2, $ledger->events);
        self::assertSame(['started', 'unsettled'], [$started['phase'], $unsettled['phase']]);
        self::assertSame($started['call'], $unsettled['call']);
        self::assertSame(RuntimeException::class, $unsettled['failure']);
    }

    public function testAnAnswerWithoutUsageSettlesWithNoTokens(): void
    {
        $ledger = new InMemoryLedger();

        $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), $ledger)->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame([], $ledger->payloads()[1]['usages']);
    }

    public function testALedgerThatCannotStoreUsageStillReturnsTheVerdict(): void
    {
        $vendor = $this->vendor(static fn (): string => VerdictDocument::fromTheDesign()->json(), new InMemoryLedger(failing: true));

        $verdict = $vendor->review('alpha', Tier::Strong, 'Review the change.');

        self::assertSame(Decision::Changes, $verdict->verdict);
    }

    private static function withUsage(string $json): TextResult
    {
        $result = new TextResult($json);
        $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 20, cachedTokens: 60, remainingTokens: 5000, totalTokens: 120, model: 'big'));

        return $result;
    }

    private function vendor(Closure $answer, InMemoryLedger $ledger = new InMemoryLedger()): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms), new VerdictParser(), new UsageLedger($ledger, new NullLogger()));
    }
}
