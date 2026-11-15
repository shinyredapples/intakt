<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Tests\Support\ReplayMarkBus;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ReplayMarks::class)]
#[UsesClass(ReplayMarkOfProject::class)]
final class ReplayMarksTest extends TestCase
{
    public function testAProjectFlaggedAsReplayHasTheReplayMark(): void
    {
        self::assertSame(ReplayMark::Replay, new ReplayMarks(new ReplayMarkBus(['demo']))->ofProject('demo'));
    }

    public function testAProjectNeverFlaggedHasTheLiveMark(): void
    {
        self::assertSame(ReplayMark::Live, new ReplayMarks(new ReplayMarkBus(['demo']))->ofProject('alpha'));
    }

    public function testAQueryNoHandlerAnsweredIsRefused(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $query): Envelope => new Envelope($query));

        $this->expectExceptionObject(new LogicException('The replay mark query did not answer a mark.'));

        new ReplayMarks($bus)->ofProject('alpha');
    }
}
