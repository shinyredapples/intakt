<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The shipped script runs here as the job's entrypoint runs it, so the checks are proven on the real script, not a copy.
#[CoversNothing]
final class StartCheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/start-check.sh';

    private string $root = '';
    private string $output = '';
    private string $work = '';
    private string $tools = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-start-check-'.bin2hex(random_bytes(4));
        $this->output = $this->root.'/output';
        $this->work = $this->root.'/work';
        $this->tools = $this->root.'/bin';
        new Filesystem()->mkdir([$this->output, $this->work, $this->tools, $this->root.'/input']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->chmod([$this->output, $this->work, $this->root.'/input'], 0o700);
        new Filesystem()->remove($this->root);
    }

    public function testAJobWhoseMountsWorkRunsInFullModeWithAnEmptyReport(): void
    {
        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame(0, $run->getExitCode());
        self::assertSame('unset', $run->getOutput());
        self::assertStringContainsString('intakt-start-check: full mode', $run->getErrorOutput());
        self::assertSame("end\n", $this->report());
        self::assertSame(['.intakt-start-check'], array_values(array_diff((array) scandir($this->output), ['.', '..'])));
    }

    public function testABrokenOutputMountIsReportedInTheLogAndTheJobRunsInReducedMode(): void
    {
        chmod($this->output, 0o500);

        $run = $this->whenTheCheckRuns([]);

        self::assertSame(0, $run->getExitCode());
        self::assertSame('output', $run->getOutput());
        self::assertStringContainsString('intakt-start-check: output: cannot write a probe file in '.$this->output, $run->getErrorOutput());
        self::assertStringContainsString('intakt-start-check: output: cannot write the report', $run->getErrorOutput());
        self::assertStringContainsString('intakt-start-check: reduced mode: output', $run->getErrorOutput());
    }

    public function testAnUnwritableWorkAreaIsReported(): void
    {
        chmod($this->work, 0o500);

        $run = $this->whenTheCheckRuns([]);

        self::assertSame('work', $run->getOutput());
        self::assertSame('work: cannot write a probe file in '.$this->work."\nend\n", $this->report());
    }

    public function testAnUnreadableInputIsReported(): void
    {
        chmod($this->root.'/input', 0o000);

        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame('input', $run->getOutput());
        self::assertSame('input: '.$this->root."/input is not readable\nend\n", $this->report());
    }

    public function testAnInputFolderThatCannotBeEnteredIsReported(): void
    {
        chmod($this->root.'/input', 0o400);

        $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame('input: '.$this->root."/input is not readable\nend\n", $this->report());
    }

    public function testEachMissingInputIsReportedAndTheReducedModeNamesTheCheckOnce(): void
    {
        $run = $this->whenTheCheckRuns(['/absent/one', '/absent/two']);

        self::assertSame('input', $run->getOutput());
        self::assertSame("input: /absent/one is missing\ninput: /absent/two is missing\nend\n", $this->report());
    }

    public function testAnAllowlistedHostThatAnswersThroughTheProxyPasses(): void
    {
        $this->curlExitsWith(0);

        $run = $this->whenTheCheckRuns([], 'api.example registry.example');

        self::assertSame('unset', $run->getOutput());
        self::assertSame("end\n", $this->report());
        self::assertSame(
            "--silent --output /dev/null --max-time 10 https://api.example/\n--silent --output /dev/null --max-time 10 https://registry.example/\n",
            file_get_contents($this->root.'/curl.log'),
        );
    }

    public function testAnAllowlistedHostTheProxyDoesNotLetThroughIsReported(): void
    {
        $this->curlExitsWith(56);

        $run = $this->whenTheCheckRuns([], 'api.example');

        self::assertSame('egress', $run->getOutput());
        self::assertSame("egress: api.example is not reachable through the proxy (curl exit 56)\nend\n", $this->report());
    }

    public function testWithoutCurlEveryAllowlistedHostIsReportedAsUnchecked(): void
    {
        $run = $this->whenTheCheckRuns([], 'api.example', path: $this->tools);

        self::assertSame('egress', $run->getOutput());
        self::assertSame("egress: curl is missing, so api.example cannot be checked\nend\n", $this->report());
    }

    public function testTheJobsCommandGetsItsOwnArgumentsAfterTheInputs(): void
    {
        $run = $this->whenTheCheckRuns([$this->root.'/input'], command: ['/bin/sh', '-c', 'printf "%s|%s" "$0" "$1"', 'first', '-- second']);

        self::assertSame('first|-- second', $run->getOutput());
    }

    public function testWithoutACommandTheCheckEndsWithAUsageError(): void
    {
        $run = new Process(['/bin/sh', '-c', $this->script(), 'intakt-start-check', $this->root.'/input'], null, $this->environment(null, (string) getenv('PATH')));
        $run->run();

        self::assertSame(64, $run->getExitCode());
        self::assertStringContainsString('intakt-start-check: no command to run', $run->getErrorOutput());
    }

    /**
     * @param list<string>      $inputs
     * @param list<string>|null $command
     */
    private function whenTheCheckRuns(array $inputs, ?string $egress = null, ?string $path = null, ?array $command = null): Process
    {
        $command ??= ['/bin/sh', '-c', 'printf "%s" "${INTAKT_REDUCED_MODE-unset}"'];
        $run = new Process(
            ['/bin/sh', '-c', $this->script(), 'intakt-start-check', ...$inputs, '--', ...$command],
            null,
            $this->environment($egress, $this->tools.':'.($path ?? (string) getenv('PATH'))),
        );
        $run->run();

        return $run;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(?string $egress, string $path): array
    {
        symlink((string) new ExecutableFinder()->find('rm'), $this->tools.'/rm');

        return [
            'PATH' => $path,
            'TMPDIR' => $this->work,
            'INTAKT_JOB_OUTPUT' => $this->output,
            'INTAKT_JOB_EGRESS' => $egress ?? false,
            'INTAKT_REDUCED_MODE' => false,
        ];
    }

    // A stand-in on the PATH, because no test may reach a host, and it records how the script called it.
    private function curlExitsWith(int $code): void
    {
        file_put_contents($this->tools.'/curl', sprintf("#!/bin/sh\necho \"\$*\" >> %s/curl.log\nexit %d\n", $this->root, $code));
        chmod($this->tools.'/curl', 0o700);
    }

    private function script(): string
    {
        return (string) file_get_contents(self::SCRIPT);
    }

    private function report(): string
    {
        return (string) file_get_contents($this->output.'/.intakt-start-check');
    }
}
