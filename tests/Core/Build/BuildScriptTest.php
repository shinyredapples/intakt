<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does, with a scripted CLI in place of Claude Code.
#[CoversNothing]
final class BuildScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/build.sh';

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';
    private string $bin = '';
    private string $prompt = '';

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        $this->work = $this->history->folder.'/job';
        $this->bin = $this->history->folder.'/bin';
        $this->prompt = $this->history->folder.'/prompt.md';
        new Filesystem()->mkdir([$this->output, $this->work, $this->bin]);
        file_put_contents($this->prompt, "Build the task.\n");
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    public function testACliThatCommitsAndWritesThePullRequestTextLeavesABundleOfTheBranchAndTheText(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            printf 'feat: build the task\n\n## Summary\n' > PR.md
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("feat: build the task\n\n## Summary\n", $this->outputFile('pull-request.md'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testTheCliGetsThePromptOnItsInputAndTheModelToRun(): void
    {
        $this->givenTheCli('printf "%s\n" "$@" > "$RECORD/arguments"; cat > "$RECORD/input"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("Build the task.\n", (string) file_get_contents($this->history->folder.'/input'));
        self::assertSame(
            ['-p', '--model', 'claude-opus-5-5', '--output-format', 'json', '--dangerously-skip-permissions'],
            explode("\n", trim((string) file_get_contents($this->history->folder.'/arguments'))),
        );
    }

    public function testTheCliWorksInAFreshCloneOnTheTasksBranchWithOnlyTheBuildClonesBranches(): void
    {
        $head = $this->history->commit('Earlier build work');
        file_put_contents($this->history->work.'/left-in-the-build-clone.txt', 'not committed');
        $this->givenTheCli('{ git rev-parse HEAD; git symbolic-ref --short HEAD; git for-each-ref --format="%(refname)"; ls; } > "$RECORD/seen"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(
            $head."\ntask\nrefs/heads/main\nrefs/heads/task\n",
            (string) file_get_contents($this->history->folder.'/seen'),
        );
    }

    public function testABuildThatMakesNoCommitLeavesNoBundle(): void
    {
        $this->givenTheCli("printf 'docs: nothing\\n' > PR.md");

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertFileExists($this->output.'/pull-request.md');
    }

    public function testAPullRequestTextThatIsALinkIsNotHandedBack(): void
    {
        $this->givenTheCli('ln -s "$RECORD/prompt.md" PR.md');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/pull-request.md');
    }

    public function testTheClisOutputGoesToTheLog(): void
    {
        $this->givenTheCli('echo "{\"type\":\"result\"}"; echo "warning" >&2');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertStringContainsString("{\"type\":\"result\"}\nwarning\n", $this->outputFile('build.log'));
    }

    public function testABranchTheBuildCloneDoesNotHoldRunsNoCli(): void
    {
        $this->givenTheCli('touch "$RECORD/ran"');

        $run = $this->whenTheBuildRuns('another-task', 'claude-sonnet-5-5');

        self::assertNotSame(0, $run->getExitCode());
        self::assertFileDoesNotExist($this->history->folder.'/ran');
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testABranchNameThatReadsAsAnOptionRunsNoCli(): void
    {
        $this->givenTheCli('touch "$RECORD/ran"');

        $run = $this->whenTheBuildRuns('--orphan', 'claude-sonnet-5-5');

        self::assertNotSame(0, $run->getExitCode());
        self::assertFileDoesNotExist($this->history->folder.'/ran');
    }

    private function givenTheCli(string $script): void
    {
        file_put_contents($this->bin.'/claude', "#!/bin/sh\n".$script."\n");
        chmod($this->bin.'/claude', 0o755);
    }

    private function whenTheBuildRuns(string $branch, string $model): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-build', $this->history->work, $this->prompt, $branch, $model],
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
        $environment = [
            'HOME' => $this->work,
            'TMPDIR' => $this->work,
            'INTAKT_JOB_OUTPUT' => $this->output,
            'PATH' => $this->bin.':'.getenv('PATH'),
            'RECORD' => $this->history->folder,
        ];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        return $environment;
    }

    private function fileAtTheBundlesHead(string $branch, string $path): string
    {
        $this->history->inMirror('fetch', '--quiet', $this->output.'/handback.bundle', 'refs/heads/'.$branch.':refs/heads/fetched');

        return $this->history->inMirror('show', 'refs/heads/fetched:'.$path)."\n";
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
