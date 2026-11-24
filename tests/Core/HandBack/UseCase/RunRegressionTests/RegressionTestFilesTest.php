<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\ChangedFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RawDiffEntries;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RegressionTestFiles;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionTestFiles::class)]
#[CoversClass(RawDiffEntries::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(Paths::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class RegressionTestFilesTest extends TestCase
{
    private BuildHistory $history;

    public function testATestFileTheHeadAddsCountsAsARegressionTest(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        self::assertSame(['tests/FooTest.php'], $this->regressionTests());
    }

    public function testATestFileTheHeadChangesCountsAsARegressionTest(): void
    {
        $this->givenMainHolds('tests/FooTest.php', "one\n");
        $this->history->commitFile('tests/FooTest.php', "two\n", 'Cover the bug');

        self::assertSame(['tests/FooTest.php'], $this->regressionTests());
    }

    public function testATestFileTheHeadRenamesCountsUnderItsNewPath(): void
    {
        $this->givenMainHolds('tests/FooTest.php', "one\ntwo\nthree\n");
        $this->history->moveFile('tests/FooTest.php', 'tests/BarTest.php', 'Rename the test');

        self::assertSame(['tests/BarTest.php'], $this->regressionTests());
    }

    public function testATestFileTheHeadDeletesDoesNotCount(): void
    {
        $this->givenMainHolds('tests/FooTest.php', "one\n");
        $this->history->removeFile('tests/FooTest.php', 'Drop the test');

        self::assertSame([], $this->regressionTests());
    }

    public function testAFileThatIsNotATestIsNotARegressionTest(): void
    {
        $this->history->commitFile('src/Foo.php', "<?php\n", 'Fix the bug');

        self::assertSame([], $this->regressionTests());
    }

    public function testATestThatMainHoldsAndTheHeadDoesNotTouchIsNotCounted(): void
    {
        $this->givenMainHolds('tests/FooTest.php', "one\n");
        $this->history->commitFile('src/Foo.php', "<?php\n", 'Fix the bug');

        self::assertSame([], $this->regressionTests());
    }

    public function testATestLinkTheHeadAddsIsNotARegressionTest(): void
    {
        $this->history->commitLink('tests/FooTest.php', '/etc/passwd', 'Add a link');

        self::assertSame([], $this->regressionTests());
    }

    public function testTheBaseIsTheMergeBaseOfMainAndTheHead(): void
    {
        $base = $this->history->inMirror('rev-parse', 'main');
        $head = $this->history->commit('Build work');
        $this->history->push('task');

        self::assertSame($base, $this->files()->baseOf($this->history->mirror, $head));
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    /**
     * @return list<string>
     */
    private function regressionTests(): array
    {
        $this->history->push('task');
        $head = $this->history->inMirror('rev-parse', 'task');
        $files = $this->files();

        return [...$files->in($this->history->mirror, $files->baseOf($this->history->mirror, $head), $head)];
    }

    private function files(): RegressionTestFiles
    {
        return new RegressionTestFiles(new RepositoryGit(new HardenedGit()), new PhpGate());
    }

    private function givenMainHolds(string $path, string $content): void
    {
        $this->history->checkout('main');
        $this->history->commitFile($path, $content, 'Add '.$path);
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
    }
}
