<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorChange;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MirrorChange::class)]
#[UsesClass(HeldChange::class)]
#[UsesClass(CommitNotRead::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class MirrorChangeTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('bin/check', "old\n");
        $this->repository->checkoutNewBranch('head');
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testTheChangeIsTheHeadsCommitAndEverythingItAddsSinceItForkedFromMain(): void
    {
        $head = $this->repository->commitFile('bin/check', "new\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('README.md', "main moved on\n");

        $change = $this->read('head');

        self::assertSame($head, $change->commit);
        self::assertStringContainsString("--- a/bin/check\n+++ b/bin/check\n@@ -1 +1 @@\n-old\n+new\n", $change->diff);
        self::assertStringNotContainsString('README.md', $change->diff);
    }

    public function testABinaryFileIsShownAsTextSoNothingIsApprovedUnseen(): void
    {
        $this->repository->commitFile('tools/run.bin', "\x01\x02 run me\n");
        $this->repository->checkout('main');

        self::assertStringContainsString("+\x01\x02 run me\n", $this->read('head')->diff);
    }

    public function testAHeadThatIsNoCommitIsNotRead(): void
    {
        $this->repository->checkout('main');

        $this->expectExceptionObject(CommitNotRead::because('missing names no commit of the repository'));

        $this->read('missing');
    }

    public function testAHeadWithoutHistoryInCommonWithMainHasNoDiffToShow(): void
    {
        $this->repository->checkoutOrphan('other');
        $this->repository->commitFile('bin/check', "other\n");
        $this->repository->checkout('main');

        $this->expectExceptionObject(CommitNotRead::because('the head and main have 0 merge bases, so the change has no single diff'));

        $this->read('other');
    }

    private function read(string $head): HeldChange
    {
        return new MirrorChange(new HardenedGit())->of($this->repository->path.'/.git', $head);
    }
}
