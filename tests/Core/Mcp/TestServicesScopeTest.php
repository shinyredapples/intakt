<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp;

use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Tests\Core\Mcp\Contract\Tool\OnePanel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers the test wiring itself: the doubles of the SDK client test must not leak into other kernel tests.
#[CoversNothing]
final class TestServicesScopeTest extends KernelTestCase
{
    public function testAKernelTestOutsideTheSdkClientTestGetsTheConfiguredPanels(): void
    {
        self::bootKernel();

        self::assertNotInstanceOf(OnePanel::class, self::getContainer()->get(Panels::class));
    }

    public function testAKernelTestOutsideTheSdkClientTestGetsTheConfiguredChangeReviews(): void
    {
        self::bootKernel();

        self::assertNotSame(self::getContainer()->get('intakt.test.mcp.review_change'), self::getContainer()->get(ChangeReviews::class));
    }
}
