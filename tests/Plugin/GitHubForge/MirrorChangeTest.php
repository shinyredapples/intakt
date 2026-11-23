<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorChange;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MirrorChange::class)]
#[UsesClass(HeldChange::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(CommitNotRead::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class MirrorChangeTest extends TestCase
{
    private TemporaryRepository $repository;

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

    public function testAFileWithoutANulByteIsShownAsTextWhateverGitWouldGuessSoNothingIsApprovedUnseen(): void
    {
        $this->repository->commitFile('tools/run.bin', "\x01\x02 run me\n");
        $this->repository->checkout('main');

        self::assertStringContainsString("+\x01\x02 run me\n", $this->read('head')->diff);
    }

    public function testABinaryFileIsNamedWithItsSizesUnderTheIndexLineThatPinsBothFullBlobIds(): void
    {
        $this->repository->commitFile('assets/logo.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\xff\xd8");
        $blob = $this->repository->objectAt('HEAD:assets/logo.png');
        $this->repository->checkout('main');

        $diff = $this->read('head')->diff;

        self::assertStringContainsString("diff --git a/assets/logo.png b/assets/logo.png\nnew file mode 100644\nindex ".str_repeat('0', strlen($blob)).'..'.$blob."\nBinary file, not shown: absent at the base, 18 bytes at the head\n", $diff);
        self::assertTrue(mb_check_encoding($diff, 'UTF-8'));
    }

    public function testAPngWhosePathGitQuotesIsNamedWithItsSizesSoTheDiffIsUtf8(): void
    {
        $this->repository->commitFile("assets/caf\u{e9}.png", "\x89PNG\r\n\x1a\n\0");
        $this->repository->checkout('main');

        $diff = $this->read('head')->diff;

        self::assertStringContainsString("diff --git \"a/assets/caf\\303\\251.png\" \"b/assets/caf\\303\\251.png\"\nnew file mode 100644\n", $diff);
        self::assertStringContainsString("Binary file, not shown: absent at the base, 9 bytes at the head\n", $diff);
        self::assertTrue(mb_check_encoding($diff, 'UTF-8'));
    }

    public function testAScriptWithANulByteKeepsItsBytesSoThePushRequestStillRefusesIt(): void
    {
        $this->repository->commitFile('tools/run.sh', "echo payload-ran\n# \0\n");
        $this->repository->checkout('main');

        self::assertStringContainsString("+++ b/tools/run.sh\n@@ -0,0 +1,2 @@\n+echo payload-ran\n+# \0\n", $this->read('head')->diff);
    }

    public function testATextFileWithBytesThatAreNotUtf8KeepsThemSoThePushRequestStillRefusesIt(): void
    {
        $this->repository->commitFile('bin/check', "caf\xe9\n");
        $this->repository->checkout('main');

        self::assertStringContainsString("+caf\xe9\n", $this->read('head')->diff);
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

    private function read(string $head): HeldChange
    {
        return new MirrorChange(new HardenedGit(), new BinaryFiles())->of($this->repository->path.'/.git', $head);
    }
}
