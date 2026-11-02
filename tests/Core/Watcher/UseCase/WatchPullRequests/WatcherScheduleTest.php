<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\UseCase\WatchPullRequests\ProjectNotWatched;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProjects;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherSchedule;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatcherSchedule::class)]
#[CoversClass(WatchedProjects::class)]
#[CoversClass(ProjectNotWatched::class)]
#[UsesClass(WatchedProject::class)]
#[UsesClass(WatchPullRequests::class)]
final class WatcherScheduleTest extends TestCase
{
    public function testTheScheduleHoldsOneRecurringWatchPerProject(): void
    {
        $schedule = new WatcherSchedule($this->projects())->getSchedule();

        self::assertCount(2, $schedule->getRecurringMessages());
    }

    public function testAProjectWithoutAWatchSectionIsRefused(): void
    {
        $this->expectException(ProjectNotWatched::class);

        $this->projects()->named('gamma');
    }

    public function testAProjectWithAWatchSectionIsReadWithItsRepositoryBaseAndTask(): void
    {
        $project = $this->projects()->named('alpha');

        self::assertSame(['alpha', 'repo', 'main', 'Review it.'], [$project->name, $project->source->repository, $project->source->base, $project->task]);
    }

    private function projects(): WatchedProjects
    {
        $watch = ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.'];

        return new WatchedProjects(['alpha' => $watch, 'beta' => $watch]);
    }
}
