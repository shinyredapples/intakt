<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract\Tool;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;

// A panel of one reviewer for every change, because the start tool test is about the server and not about panel configuration.
final readonly class OnePanel implements Panels
{
    public function panelForChange(string $project, ChangeDescription $change): Panel
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);

        return new Panel([$reviewer], QuorumPolicy::All);
    }
}
