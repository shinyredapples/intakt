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
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(SymfonyAiVendor::class)]
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
final class SymfonyAiVendorTest extends TestCase
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

    private function vendor(Closure $answer): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
        ], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::theApplicationsDispatcher());
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms), new VerdictParser());
    }
}
