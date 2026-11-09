<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Contract;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiveEvent::class)]
final class LiveEventTest extends TestCase
{
    public function testAnEventCarriesItsTopicAndItsTurboStream(): void
    {
        $event = new LiveEvent('dashboard/projects', '<turbo-stream action="refresh"></turbo-stream>');

        self::assertSame(['dashboard/projects', '<turbo-stream action="refresh"></turbo-stream>'], [$event->topic, $event->turboStream]);
    }

    public function testAnEventWithoutATopicIsRefusedBecauseNoPageCouldEverReceiveIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LiveEvent('', '<turbo-stream action="refresh"></turbo-stream>');
    }

    public function testAnEventWithABlankTopicIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LiveEvent(' ', '<turbo-stream action="refresh"></turbo-stream>');
    }
}
