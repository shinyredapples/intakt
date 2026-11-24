<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The job's script on a real mirror and bundle, with the test command stood in by a shell step, because a job container is not available to a test.
#[CoversNothing]
final class RegressionScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/regression.sh';

    private const string STEP = 'grep -q fixed src/Foo.txt';

    private BuildHistory $history;

    private string $base = '';

    private string $head = '';

    public function testTheBaseRunFailsWhenTheTestFailsOnTheBaseCodeEvenThoughTheHeadsTestIsInPlace(): void
    {
        self::assertSame("failed 1\n", $this->resultOf($this->base, true));
    }

    public function testTheHeadRunPassesWhenTheTestPassesOnTheHead(): void
    {
        self::assertSame("passed\n", $this->resultOf($this->head, false));
    }

    public function testTheBaseRunLaysTheHeadsTestFileOverTheBase(): void
    {
        self::assertSame("passed\n", $this->resultOf($this->base, true, 'test -f tests/FooTest.php'));
        self::assertSame("failed 1\n", $this->resultOf($this->base, false, 'test -f tests/FooTest.php'));
    }

    public function testATestThatPassesOnTheBaseToo(): void
    {
        self::assertSame("passed\n", $this->resultOf($this->base, true, 'grep -q buggy src/Foo.txt'));
    }

    public function testAnInstallStepThatFailsLeavesNotInstalled(): void
    {
        self::assertSame("not-installed\n", $this->scriptResult(['task', $this->head, '-', '0', 'false', self::STEP]));
    }

    public function testABundleOfAnotherBranchLeavesNotApplied(): void
    {
        self::assertSame("not-applied\n", $this->scriptResult(['other', $this->head, '-', '0', '-', self::STEP]));
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->base = $this->history->commitFile('src/Foo.txt', "buggy\n", 'Start the project');
        $this->history->push('task');
        $this->history->inMirror('update-ref', 'refs/heads/main', $this->base);
        $this->history->commitFile('src/Foo.txt', "fixed\n", 'Fix the bug');
        $this->head = $this->history->commitFile('tests/FooTest.php', "test\n", 'Cover the bug');
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function resultOf(string $revision, bool $overlay, string $step = self::STEP): string
    {
        return $this->scriptResult(['task', $revision, $overlay ? 'task' : '-', $overlay ? '1' : '0', '-', ...($overlay ? ['tests/FooTest.php'] : []), $step]);
    }

    /**
     * @param list<string> $arguments
     */
    private function scriptResult(array $arguments): string
    {
        $output = $this->history->folder.'/output-'.bin2hex(random_bytes(3));
        $work = $this->history->folder.'/work-'.bin2hex(random_bytes(3));
        new Filesystem()->mkdir([$output, $work]);
        $process = new Process(
            ['/bin/sh', self::SCRIPT, $this->history->mirror, $this->history->bundle('main..task'), ...$arguments],
            null,
            ['INTAKT_JOB_OUTPUT' => $output, 'TMPDIR' => $work],
        );
        $process->run();

        return (string) file_get_contents($output.'/regression-result');
    }
}
