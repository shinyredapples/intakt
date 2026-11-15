<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Tests\Support\ReplayMarkBus;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ReplayMarks::class)]
#[UsesClass(ReplayMarkOfProject::class)]
#[UsesClass(ReplayMarkOfRound::class)]
#[UsesClass(RoundNotFound::class)]
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

    public function testAStoredRoundKeepsItsLiveMarkAfterItsProjectWasFlagged(): void
    {
        self::assertSame(ReplayMark::Live, new ReplayMarks(new ReplayMarkBus(['demo'], ['r1' => ReplayMark::Live]))->ofRound('r1', 'demo'));
    }

    public function testAStoredRoundOfAProjectNoLongerFlaggedKeepsItsReplayMark(): void
    {
        self::assertSame(ReplayMark::Replay, new ReplayMarks(new ReplayMarkBus([], ['r1' => ReplayMark::Replay]))->ofRound('r1', 'demo'));
    }

    public function testARoundTheReviewModuleNeverStoredHasTheMarkOfItsProject(): void
    {
        self::assertSame(ReplayMark::Replay, new ReplayMarks(new ReplayMarkBus(['demo']))->ofRound('r1', 'demo'));
    }

    public function testAFailureOfTheRoundQueryOtherThanAnUnknownRoundIsNotHidden(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $failed = new HandlerFailedException(new Envelope(new ReplayMarkOfRound('r1')), [new RuntimeException('database gone')]);
        $bus->method('dispatch')->willThrowException($failed);

        $this->expectExceptionObject($failed);

        new ReplayMarks($bus)->ofRound('r1', 'demo');
    }

    public function testAQueryNoHandlerAnsweredIsRefused(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $query): Envelope => new Envelope($query));

        $this->expectExceptionObject(new LogicException('The replay mark query did not answer a mark.'));

        new ReplayMarks($bus)->ofProject('alpha');
    }
}
