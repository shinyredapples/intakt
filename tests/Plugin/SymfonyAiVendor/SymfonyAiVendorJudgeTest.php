<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(JudgeAgent::class)]
#[UsesClass(JudgePrompt::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(Fence::class)]
#[UsesClass(UntrustedSource::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
final class SymfonyAiVendorJudgeTest extends KernelTestCase
{
    public function testTheVendorJudgesOnTheLightTierAndReturnsTheMarks(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'strong' => ['provider' => 'anthropic', 'model' => 'big'],
            'light' => ['provider' => 'anthropic', 'model' => 'small'],
        ]]], []);
        $models = [];
        $platform = MockPlatformFactory::createPlatform(static function (Model $model) use (&$models): string {
            $models[] = $model->getName();

            return '{"marks":[{"id":"F1","mark":"defer"}]}';
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new InMemoryMessageStore());
        $vendor = new SymfonyAiVendor(new TierPlatform($resolver, $platforms), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock('2026-10-27 12:00:00 UTC'), new NullLogger()));
        $finding = new Finding('F1', Severity::P2, null, null, null, 'Text.', FixableBy::Builder, false, null, null);

        $judgement = $vendor->judge('alpha', new JudgeRequest('Add a cart.', [$finding]));

        self::assertEquals([new JudgedFinding('F1', Mark::Defer)], $judgement->marks);
        self::assertSame(['small'], $models);
    }
}
