<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\UseCase;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\UseCase\ListProjects\ListedProject;
use Intakt\Core\Ai\UseCase\ListProjects\ListProjectsTool;
use Intakt\Core\Ai\UseCase\ListProjects\ProjectList;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// The tool as the container wires it, because a stubbed bus cannot show that the query reaches the bus that handles it.
#[Group('persistence')]
#[CoversClass(ListProjectsTool::class)]
#[UsesClass(ProjectList::class)]
#[UsesClass(ListedProject::class)]
final class ListProjectsOnTheInstallTest extends KernelTestCase
{
    public function testAConfiguredProjectFlaggedAsReplayIsListedWithTheReplayMark(): void
    {
        $this->configure(['demo']);
        self::getContainer()->get('command.bus')->dispatch(new FlagProjectAsReplay('demo'));

        self::assertSame(ReplayMark::Replay, $this->tool()()->projects[0]->mark);
    }

    public function testAConfiguredProjectNeverFlaggedIsListedWithTheLiveMark(): void
    {
        $this->configure(['alpha']);

        self::assertSame(ReplayMark::Live, $this->tool()()->projects[0]->mark);
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
    }

    /**
     * @param list<string> $projects
     */
    private function configure(array $projects): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames(...$projects));
        self::getContainer()->set(TierResolver::class, $resolver);
    }

    private function tool(): ListProjectsTool
    {
        $tool = self::getContainer()->get(ListProjectsTool::class);
        self::assertInstanceOf(ListProjectsTool::class, $tool);

        return $tool;
    }
}
