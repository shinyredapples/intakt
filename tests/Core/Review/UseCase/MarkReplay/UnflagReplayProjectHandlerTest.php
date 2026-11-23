<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkReplay;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\UnflagReplayProject;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\MarkReplay\ReplayMarkOfProjectHandler;
use Intakt\Core\Review\UseCase\MarkReplay\UnflagReplayProjectHandler;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[Group('persistence')]
#[CoversClass(UnflagReplayProjectHandler::class)]
#[CoversClass(UnflagReplayProject::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(ReplayMarkOfProject::class)]
#[UsesClass(ReplayMarkOfProjectHandler::class)]
#[UsesClass(ReplayMark::class)]
#[UsesClass(ReplayProject::class)]
final class UnflagReplayProjectHandlerTest extends KernelTestCase
{
    public function testAnUnflaggedProjectIsLiveAgain(): void
    {
        $this->command(new FlagProjectAsReplay('demo-replay'));

        $this->command(new UnflagReplayProject('demo-replay'));

        self::assertSame(ReplayMark::Live, $this->markOf('demo-replay'));
    }

    public function testUnflaggingOneProjectKeepsAnotherAReplay(): void
    {
        $this->command(new FlagProjectAsReplay('demo-replay'));
        $this->command(new FlagProjectAsReplay('other-replay'));

        $this->command(new UnflagReplayProject('demo-replay'));

        self::assertSame(ReplayMark::Replay, $this->markOf('other-replay'));
    }

    public function testUnflaggingAProjectThatIsNoReplayChangesNothing(): void
    {
        $this->command(new UnflagReplayProject('demo-replay'));

        self::assertSame(ReplayMark::Live, $this->markOf('demo-replay'));
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $entities = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    private function command(object $command): void
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);
        $commands->dispatch($command);
    }

    private function markOf(string $project): ReplayMark
    {
        $queries = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queries);
        $mark = $queries->dispatch(new ReplayMarkOfProject($project))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ReplayMark::class, $mark);

        return $mark;
    }
}
