<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkReplay;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayProject::class)]
final class FlagProjectAsReplayHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entities;

    public function testFlaggingAProjectNotFlaggedYetStoresItAsAReplay(): void
    {
        new FlagProjectAsReplayHandler($this->entities)(new FlagProjectAsReplay('alpha'));

        self::assertSame(['alpha'], $this->flaggedProjects());
    }

    public function testFlaggingAProjectAlreadyFlaggedKeepsOneRowAndAnOpenEntityManager(): void
    {
        $this->storeFlagOfAnotherWorker('alpha');

        new FlagProjectAsReplayHandler($this->entities)(new FlagProjectAsReplay('alpha'));

        self::assertSame(['alpha'], $this->flaggedProjects());
        self::assertTrue($this->entities->isOpen());
    }

    public function testADuplicateFlagInsideAnOuterTransactionLeavesThatTransactionToCommitItsOtherWrites(): void
    {
        $this->storeFlagOfAnotherWorker('alpha');
        $connection = $this->entities->getConnection();

        $connection->transactional(function () use ($connection): void {
            new FlagProjectAsReplayHandler($this->entities)(new FlagProjectAsReplay('alpha'));
            $connection->insert('review_replay_project', ['project' => 'beta']);
        });

        self::assertEqualsCanonicalizing(['alpha', 'beta'], $this->flaggedProjects());
        self::assertFalse($connection->isTransactionActive());
    }

    public function testFlaggingOneProjectLeavesTheFlagOfAnotherInPlace(): void
    {
        $this->storeFlagOfAnotherWorker('beta');

        new FlagProjectAsReplayHandler($this->entities)(new FlagProjectAsReplay('alpha'));

        self::assertEqualsCanonicalizing(['alpha', 'beta'], $this->flaggedProjects());
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $this->entities = $container->get('doctrine.orm.entity_manager');
        new SchemaTool($this->entities)->createSchema([$this->entities->getClassMetadata(ReplayProject::class)]);
    }

    private function storeFlagOfAnotherWorker(string $project): void
    {
        $this->entities->getConnection()->insert('review_replay_project', ['project' => $project]);
    }

    /**
     * @return list<mixed>
     */
    private function flaggedProjects(): array
    {
        return $this->entities->getConnection()->fetchFirstColumn('SELECT project FROM review_replay_project');
    }
}
