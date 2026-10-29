<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelWithoutReviewers;
use Intakt\Core\Review\Contract\QuorumPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Panel::class)]
#[UsesClass(PanelWithoutReviewers::class)]
#[UsesClass(QuorumPolicy::class)]
final class PanelTest extends TestCase
{
    public function testAPanelWithoutReviewersCannotBeBuilt(): void
    {
        $this->expectException(PanelWithoutReviewers::class);

        new Panel([], QuorumPolicy::All);
    }
}
