<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\KeptSnapshots;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\RemoveKeptSnapshot;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RemoveKeptSnapshot::class)]
#[CoversClass(KeptSnapshots::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(RoundJudged::class)]
final class RemoveKeptSnapshotTest extends TestCase
{
    private TemporaryRepository $repository;
    private LocalGitForge $forge;
    private BufferingLogger $log;

    public function testTheSnapshotKeptForAJudgedRoundIsRemovedWithItsWorktreeEntry(): void
    {
        $snapshot = $this->forge->keepSnapshotOf($this->head());

        $this->listener()(new RoundJudged('round-1', $snapshot));

        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testACheckoutThatIsNoKeptSnapshotIsLeftAlone(): void
    {
        $this->listener()(new RoundJudged('round-1', $this->repository->path));

        self::assertFileExists($this->repository->path.'/src/Feature.php');
    }

    public function testASnapshotACommandRemovesItselfIsLeftToThatCommand(): void
    {
        $left = $this->forge->withSnapshotOf($this->head(), function (string $snapshot): bool {
            $this->listener()(new RoundJudged('round-1', $snapshot));

            return is_dir($snapshot);
        });

        self::assertTrue($left);
    }

    public function testAKeptSnapshotThatCannotBeRemovedIsLoggedAndNotThrown(): void
    {
        $directory = sys_get_temp_dir().'/'.LocalGitForge::KEPT_SNAPSHOT_PREFIX.bin2hex(random_bytes(8));
        new Filesystem()->mkdir($directory);

        try {
            $this->listener()(new RoundJudged('round-1', (string) realpath($directory)));
        } finally {
            new Filesystem()->remove($directory);
        }

        $logs = $this->log->cleanLogs();
        self::assertIsArray($logs[0] ?? null);
        self::assertSame('warning', $logs[0][0]);
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
        $this->forge = new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser());
        $this->log = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function head(): CommitId
    {
        return $this->forge->change(new ChangeId('sample'))->commits->head;
    }

    private function listener(): RemoveKeptSnapshot
    {
        return new RemoveKeptSnapshot(new KeptSnapshots($this->log));
    }
}
