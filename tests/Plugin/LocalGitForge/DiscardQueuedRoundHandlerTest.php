<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DiscardQueuedRoundHandler;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\KeptSnapshots;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(DiscardQueuedRoundHandler::class)]
#[CoversClass(DiscardQueuedRound::class)]
#[CoversClass(QueuedRound::class)]
#[UsesClass(KeptSnapshots::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
final class DiscardQueuedRoundHandlerTest extends TestCase
{
    private TemporaryRepository $repository;
    private LocalGitForge $forge;

    public function testTheSnapshotKeptForARoundThatWasRolledBackIsRemovedWithItsWorktreeEntry(): void
    {
        $snapshot = $this->forge->keepSnapshotOf($this->forge->change(new ChangeId('sample'))->commits->head);

        $this->handler()(new DiscardQueuedRound(new QueuedRound('round-1', $snapshot)));

        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
    }

    public function testACheckoutThatIsNoKeptSnapshotIsLeftAlone(): void
    {
        $this->handler()(new DiscardQueuedRound(new QueuedRound('round-1', $this->repository->path)));

        self::assertFileExists($this->repository->path.'/src/Feature.php');
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
        $this->forge = new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser());
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function handler(): DiscardQueuedRoundHandler
    {
        return new DiscardQueuedRoundHandler(new KeptSnapshots(new BufferingLogger()));
    }
}
