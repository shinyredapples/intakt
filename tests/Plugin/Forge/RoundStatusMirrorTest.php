<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
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
    public function testTheForgeReconcilesTheStatusOfTheChange(): void
    {
        $labels = $this->createMock(StatusLabels::class);
        $labels->expects(self::once())->method('reconcile')->with('alpha', new ChangeId('7'));

        new RoundStatusMirror(self::createStub(LoggerInterface::class), $labels)->reconcile('alpha', new ChangeId('7'));
    }

    public function testAForgeWithoutLabelsIsLeftAlone(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new RoundStatusMirror($logger)->reconcile('alpha', new ChangeId('7'));
    }

    public function testAFailedWriteIsLoggedAndTheRoundGoesOn(): void
    {
        $labels = self::createStub(StatusLabels::class);
        $labels->method('reconcile')->willThrowException(new ForgeWriteFailed('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        new RoundStatusMirror($logger, $labels)->reconcile('alpha', new ChangeId('7'));
    }

    public function testAFailedReadIsLoggedAndTheRoundGoesOn(): void
    {
        $labels = self::createStub(StatusLabels::class);
        $labels->method('reconcile')->willThrowException(new ForgeReadFailed('GitHub answered 503.'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        new RoundStatusMirror($logger, $labels)->reconcile('alpha', new ChangeId('7'));
    }
}
