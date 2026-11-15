<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\CapturingLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CapturingLiveUpdates::class)]
#[CoversClass(MercureLiveUpdates::class)]
final class CapturingLiveUpdatesWiringTest extends KernelTestCase
{
    public function testEveryPublisherOfLiveUpdatesGoesThroughTheCapture(): void
    {
        self::assertInstanceOf(CapturingLiveUpdates::class, self::getContainer()->get(LiveUpdates::class));
    }

    public function testTheCaptureHandsEachUpdateToTheMercureHub(): void
    {
        self::assertInstanceOf(MercureLiveUpdates::class, self::getContainer()->get(CapturingLiveUpdates::class.'.inner'));
    }
}
