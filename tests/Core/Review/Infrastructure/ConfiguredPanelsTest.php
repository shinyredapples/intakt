<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredPanels::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelNotConfigured::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(QuorumPolicy::class)]
final class ConfiguredPanelsTest extends TestCase
{
    public function testThePanelOfAProjectCarriesItsReviewersInConfigOrderAndItsPolicy(): void
    {
        $panels = new ConfiguredPanels(['alpha' => [
            'quorum' => 'majority',
            'reviewers' => [
                ['role' => 'correctness', 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Logic.', 'budget' => 5],
                ['role' => 'style', 'tier' => 'light', 'provider' => 'openai', 'prompt' => 'Style.', 'budget' => 2],
            ],
        ]]);

        $panel = $panels->panelFor('alpha');

        self::assertSame(QuorumPolicy::Majority, $panel->quorum);
        self::assertSame(['correctness', 'style'], array_map(static fn (PanelReviewer $reviewer): string => $reviewer->role, $panel->reviewers));
        self::assertSame(Tier::Light, $panel->reviewers[1]->tier);
        self::assertSame(Provider::OpenAi, $panel->reviewers[1]->provider);
        self::assertSame('Style.', $panel->reviewers[1]->prompt);
        self::assertSame(2, $panel->reviewers[1]->budget);
    }

    public function testAProjectWithoutAPanelIsRefused(): void
    {
        $this->expectException(PanelNotConfigured::class);
        $this->expectExceptionMessage('Project "beta" has no review panel configured.');

        new ConfiguredPanels([])->panelFor('beta');
    }
}
