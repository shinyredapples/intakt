<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkReplay;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use Override;
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

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $this->entities = $container->get('doctrine.orm.entity_manager');
        new SchemaTool($this->entities)->createSchema([$this->entities->getClassMetadata(ReplayProject::class)]);
    }

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

    // Forces the order of two flags at the same moment: the other worker's row commits after this flag could have looked for it, so a lookup finds nothing and only the write meets the row.
    public function testAFlagWhoseTwinStoredTheRowAfterAnyLookupKeepsOneRowAndAnOpenEntityManager(): void
    {
        $this->storeFlagOfAnotherWorker('alpha');
        $lookupBeforeTheTwin = new class($this->entities) extends EntityManagerDecorator {
            #[Override]
            public function find(string $className, mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object
            {
                return null;
            }
        };

        new FlagProjectAsReplayHandler($lookupBeforeTheTwin)(new FlagProjectAsReplay('alpha'));

        self::assertSame(['alpha'], $this->flaggedProjects());
        self::assertTrue($this->entities->isOpen());
    }

    public function testFlaggingOneProjectLeavesTheFlagOfAnotherInPlace(): void
    {
        $this->storeFlagOfAnotherWorker('beta');

        new FlagProjectAsReplayHandler($this->entities)(new FlagProjectAsReplay('alpha'));

        self::assertEqualsCanonicalizing(['alpha', 'beta'], $this->flaggedProjects());
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
