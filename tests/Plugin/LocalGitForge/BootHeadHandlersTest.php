<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\BootHead\BootHarnessAtMainHandler;
use Intakt\Plugin\LocalGitForge\UseCase\BootHead\BootHarnessHandler;
use Intakt\Plugin\LocalGitForge\UseCase\BootHead\InstanceBoot;
use Intakt\Plugin\LocalGitForge\UseCase\BootHead\MainCommitHandler;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootHarnessHandler::class)]
#[CoversClass(BootHarnessAtMainHandler::class)]
#[CoversClass(MainCommitHandler::class)]
#[UsesClass(MainCommit::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootHarness::class)]
#[UsesClass(BootHarnessAtMain::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(InstanceBoot::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
final class BootHeadHandlersTest extends TestCase
{
    private const string FAILING = "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"failed\",\"reason\":\"main harness\"}');\nexit(1);\n";
    private const string READY = "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"ready\"}');\n";

    private TemporaryRepository $repository;
    private CommitId $main;
    private BootAt $head;

    public function testBootingTheHarnessBootsACommitWithTheHarnessItCarries(): void
    {
        $outcome = new BootHarnessHandler(new InstanceBoot())(new BootHarness($this->head));

        self::assertSame(BootState::Booted, $outcome->state);
    }

    public function testBootingAtMainBootsTheHeadWithMainsHarness(): void
    {
        $outcome = new BootHarnessAtMainHandler(new InstanceBoot())(new BootHarnessAtMain($this->head, $this->main));

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('main harness', $outcome->reason);
    }

    public function testTheMainCommitIsTheCommitTheBaseBranchPointsAt(): void
    {
        $main = new MainCommitHandler()(new MainCommit($this->head->source));

        self::assertTrue($this->main->equals($main));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('composer.json', '{"name": "intakt-test/boot"}');
        $this->main = new CommitId($this->repository->commitFile('bin/instance', self::FAILING));
        $this->repository->checkoutNewBranch('feature');
        $this->head = new BootAt(new ChangeSource($this->repository->path, 'main'), new CommitId($this->repository->commitFile('bin/instance', self::READY)));
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }
}
