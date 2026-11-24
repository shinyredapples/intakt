<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\UseCase;

use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\UseCase\ListProjects\ListedProject;
use Intakt\Core\Ai\UseCase\ListProjects\ListProjectsTool;
use Intakt\Core\Ai\UseCase\ListProjects\ProjectList;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ListProjectsTool::class)]
#[UsesClass(ProjectList::class)]
#[UsesClass(ListedProject::class)]
final class ListProjectsToolTest extends TestCase
{
    public function testItReturnsEveryConfiguredProject(): void
    {
        $tool = $this->tool(['alpha', 'beta'], []);

        self::assertSame(['alpha', 'beta'], array_map(static fn (ListedProject $project): string => $project->name, $tool()->projects));
    }

    public function testItReturnsAnEmptyListWhenNoProjectIsConfigured(): void
    {
        self::assertSame([], $this->tool([], [])()->projects);
    }

    public function testAProjectFlaggedAsReplayIsListedWithTheReplayMark(): void
    {
        $tool = $this->tool(['demo'], ['demo' => ReplayMark::Replay]);

        self::assertSame(ReplayMark::Replay, $tool()->projects[0]->mark);
    }

    public function testAProjectNotFlaggedIsListedWithTheLiveMark(): void
    {
        $tool = $this->tool(['alpha'], []);

        self::assertSame(ReplayMark::Live, $tool()->projects[0]->mark);
    }

    /**
     * @param list<string>              $projects
     * @param array<string, ReplayMark> $replays
     */
    private function tool(array $projects, array $replays): ListProjectsTool
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames(...$projects));
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (ReplayMarkOfProject $query): Envelope => new Envelope($query)->with(new HandledStamp($replays[$query->project] ?? ReplayMark::Live, 'handler')));

        return new ListProjectsTool($resolver, $bus);
    }
}
