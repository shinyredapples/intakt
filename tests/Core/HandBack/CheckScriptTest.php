<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does.
#[CoversNothing]
final class CheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/check.sh';

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        $this->work = $this->history->folder.'/job';
        new Filesystem()->mkdir([$this->output, $this->work]);
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    public function testStepsThatAllPassLeavePassedAndTheirOutputInTheLog(): void
    {
        $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'cat made.txt', 'echo second step');

        self::assertSame(0, $run->getExitCode());
        self::assertSame("passed\n", $this->outputFile('check-result'));
        self::assertSame("\$ cat made.txt\nmade by the build\n\$ echo second step\nsecond step\n", $this->outputFile('check.log'));
    }

    public function testTheFirstFailingStepEndsTheCheckWithItsExitCode(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'exit 3', 'echo never');

        self::assertSame(3, $run->getExitCode());
        self::assertSame("failed 3\n", $this->outputFile('check-result'));
        self::assertStringNotContainsString('never', $this->outputFile('check.log'));
    }

    public function testTheStepsRunInAFreshCloneOfTheMirrorAtTheBundlesHead(): void
    {
        $head = $this->history->commit('Build work');
        file_put_contents($this->history->work.'/left-by-the-build.txt', 'not committed');

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            'test "$(git rev-parse HEAD)" = '.$head,
            'git merge-base --is-ancestor main HEAD',
            'test ! -e left-by-the-build.txt',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testABundleWithoutTheTasksBranchLeavesNotAppliedAndRunsNoStep(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'another-task', 'touch ran');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-applied\n", $this->outputFile('check-result'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMissingBundleLeavesNotApplied(): void
    {
        $run = $this->whenTheCheckRuns($this->history->folder.'/missing.bundle', 'task', 'true');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-applied\n", $this->outputFile('check-result'));
    }

    private function whenTheCheckRuns(string $bundle, string $branch, string ...$steps): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-check', $this->history->mirror, $bundle, $branch, '--', ...array_values($steps)],
            null,
            $this->environment(),
        );
        $run->run();

        return $run;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        // As the container sets them, and without the host's git variables, which would point git at another repository.
        $environment = ['HOME' => $this->work, 'TMPDIR' => $this->work, 'INTAKT_JOB_OUTPUT' => $this->output];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        return $environment;
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
