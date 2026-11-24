<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Plugin\Forge\Contract\StatusLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RoundStatusMirror::class)]
#[UsesClass(ChangeId::class)]
final class RoundStatusMirrorTest extends TestCase
{
    public function testTheStatusIsMirroredToTheForge(): void
    {
        $labels = $this->createMock(StatusLabels::class);
        $labels->expects(self::once())->method('mirror')->with('alpha', new ChangeId('7'), RoundStatusLabel::InReview);

        new RoundStatusMirror(self::createStub(LoggerInterface::class), $labels)->show('alpha', new ChangeId('7'), RoundStatusLabel::InReview);
    }

    public function testAForgeWithoutLabelsIsLeftAlone(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new RoundStatusMirror($logger)->show('alpha', new ChangeId('7'), RoundStatusLabel::WaitingOnYou);
    }

    public function testAFailedWriteIsLoggedAndTheRoundGoesOn(): void
    {
        $labels = self::createStub(StatusLabels::class);
        $labels->method('mirror')->willThrowException(new ForgeWriteFailed('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        new RoundStatusMirror($logger, $labels)->show('alpha', new ChangeId('7'), RoundStatusLabel::InReview);
    }
}
