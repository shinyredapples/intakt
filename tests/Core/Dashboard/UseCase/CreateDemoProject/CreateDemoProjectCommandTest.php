<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\CreateDemoProject;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[Group('persistence')]
#[CoversClass(CreateDemoProjectCommand::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayMarkOfProject::class)]
#[UsesClass(ReplayMark::class)]
final class CreateDemoProjectCommandTest extends KernelTestCase
{
    public function testTheDemoProjectIsRefusedOutsideTheComposeDemo(): void
    {
        $tester = $this->tester('');

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('only on the compose demo', $tester->getDisplay());
        self::assertSame(ReplayMark::Live, $this->markOfTheDemoProject());
    }

    public function testOnTheComposeDemoTheDemoProjectIsCreatedMarkedAsAReplay(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(ReplayMark::Replay, $this->markOfTheDemoProject());
    }

    public function testTheCreatedDemoProjectIsNamedInTheOutput(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute([]);

        self::assertStringContainsString(CreateDemoProjectCommand::NAME, $tester->getDisplay());
    }

    public function testCreatingTheDemoProjectAgainKeepsItAReplay(): void
    {
        $this->tester('intakt')->execute([]);
        $again = $this->tester('intakt');

        $again->execute([]);

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertSame(ReplayMark::Replay, $this->markOfTheDemoProject());
        self::assertSame(1, $this->replayProjectRows());
    }

    public function testTheDemoProjectIsSeparateFromTheSampleProject(): void
    {
        $this->tester('intakt')->execute([]);

        self::assertNotSame(SampleChange::PROJECT, CreateDemoProjectCommand::NAME);
        self::assertSame(ReplayMark::Live, $this->markOf(SampleChange::PROJECT));
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $entities = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    private function tester(string $composeService): CommandTester
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);
        $application = new Application();
        $application->addCommand(new CreateDemoProjectCommand($commands, $composeService));

        return new CommandTester($application->find('intakt:demo:create'));
    }

    private function replayProjectRows(): int
    {
        $entities = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entities);

        $rows = $entities->getConnection()->fetchOne('SELECT COUNT(*) FROM review_replay_project');
        self::assertIsInt($rows);

        return $rows;
    }

    private function markOfTheDemoProject(): ReplayMark
    {
        return $this->markOf(CreateDemoProjectCommand::NAME);
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
