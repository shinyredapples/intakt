<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootGateOff;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(BootGateOff::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(HeadDecision::class)]
#[UsesClass(WatchedProject::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(OpenChange::class)]
final class BootGateOffTest extends KernelTestCase
{
    public function testTheWatcherBootsNoHeadUntilTheBootRunsInAJobSandbox(): void
    {
        self::assertInstanceOf(BootGateOff::class, self::getContainer()->get(HeadGate::class));
    }

    public function testAGreenHeadIsAdmittedUnchangedWithoutABoot(): void
    {
        $green = new HeadDecision(new OpenChange(new ChangeId('7'), new CommitId(str_repeat('b', 40)), new DateTimeImmutable('2026-11-13T10:00:00+00:00')), HeadAdmission::Green);

        self::assertSame($green, new BootGateOff()->admit(new WatchedProject('alpha', new ChangeSource('/repos/alpha', 'main'), 'Review it.'), $green));
    }
}
