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
    private const string MARK = 'mark-of-this-run';
    private const string CHECK = 'intakt-start-check '.self::MARK.': ';

    private string $root = '';
    private string $output = '';
    private string $work = '';
    private string $tools = '';

    public function testAJobWhoseMountsWorkRunsInFullModeAndLeavesNothingInItsOutput(): void
    {
        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame(0, $run->getExitCode());
        self::assertSame('unset', $run->getOutput());
        self::assertSame("output: ok\nwork: ok\ninput: ".$this->root."/input: ok\nfull mode\n", $this->logOf($run));
        self::assertSame([], array_values(array_diff((array) scandir($this->output), ['.', '..'])));
    }

    public function testABrokenOutputMountIsReportedInTheLogAndTheJobRunsInReducedMode(): void
    {
        chmod($this->output, 0o500);

        $run = $this->whenTheCheckRuns([]);

        self::assertSame(0, $run->getExitCode());
        self::assertSame('output', $run->getOutput());
        self::assertSame('output: cannot write a probe file in '.$this->output."\nwork: ok\nreduced mode: output\n", $this->logOf($run));
    }

    public function testAnUnwritableWorkAreaIsReported(): void
    {
        chmod($this->work, 0o500);

        $run = $this->whenTheCheckRuns([]);

        self::assertSame('work', $run->getOutput());
        self::assertStringContainsString('work: cannot write a probe file in '.$this->work."\nreduced mode: work\n", $this->logOf($run));
    }

    public function testAnUnreadableInputIsReported(): void
    {
        chmod($this->root.'/input', 0o000);

        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame('input', $run->getOutput());
        self::assertStringContainsString('input: '.$this->root."/input is not readable\nreduced mode: input\n", $this->logOf($run));
    }

    public function testAnInputFolderThatCannotBeEnteredIsReported(): void
    {
        chmod($this->root.'/input', 0o400);

        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertStringContainsString('input: '.$this->root."/input is not readable\n", $this->logOf($run));
    }

    public function testEachMissingInputIsReportedAndTheReducedModeNamesTheCheckOnce(): void
    {
        $run = $this->whenTheCheckRuns(['/absent/one', '/absent/two']);

        self::assertSame('input', $run->getOutput());
        self::assertStringContainsString("input: /absent/one is missing\ninput: /absent/two is missing\nreduced mode: input\n", $this->logOf($run));
    }

    public function testTheModeLineIsTheLastLineBeforeTheJobsCommandRuns(): void
    {
        $run = $this->startCheckWith(['--', '/bin/sh', '-c', 'printf "intakt-start-check: from the job\n" >&2'], $this->environment(null, (string) getenv('PATH')));

        self::assertStringEndsWith("full mode\nintakt-start-check: from the job\n", $run->getErrorOutput());
    }

    public function testAnAllowlistedHostThatAnswersThroughTheProxyPasses(): void
    {
        $this->curlExitsWith(0);

        $run = $this->whenTheCheckRuns([], 'api.example registry.example');

        self::assertSame('unset', $run->getOutput());
        self::assertStringEndsWith("egress: api.example: ok\negress: registry.example: ok\nfull mode\n", $this->logOf($run));
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
        self::assertStringEndsWith("egress: api.example is not reachable through the proxy (curl exit 56)\nreduced mode: egress\n", $this->logOf($run));
    }

    public function testWithoutCurlEveryAllowlistedHostIsReportedAsUnchecked(): void
    {
        $run = $this->whenTheCheckRuns([], 'api.example', path: $this->tools);

        self::assertSame('egress', $run->getOutput());
        self::assertStringEndsWith("egress: curl is missing, so api.example cannot be checked\nreduced mode: egress\n", $this->logOf($run));
    }

    public function testTheJobsCommandGetsItsOwnArgumentsAfterTheInputs(): void
    {
        $run = $this->startCheckWith([$this->root.'/input', '--', '/bin/sh', '-c', 'printf "%s|%s" "$0" "$1"', 'first', '-- second'], $this->environment(null, (string) getenv('PATH')));

        self::assertSame('first|-- second', $run->getOutput());
    }

    public function testWithoutACommandTheCheckEndsWithAUsageError(): void
    {
        $run = $this->startCheckWith([$this->root.'/input'], $this->environment(null, (string) getenv('PATH')));

        self::assertSame(64, $run->getExitCode());
        self::assertStringContainsString(self::CHECK.'no command to run', $run->getErrorOutput());
    }

    public function testTheJobsCommandSeesTheMarkNeitherInItsArgumentsNorInItsEnvironment(): void
    {
        $run = $this->startCheckWith(['--', '/bin/sh', '-c', 'env; printf "%s\n" "$0" "$@"', 'first'], $this->environment(null, (string) getenv('PATH')));

        self::assertSame(0, $run->getExitCode());
        self::assertStringContainsString("first\n", $run->getOutput());
        self::assertStringNotContainsString(self::MARK, $run->getOutput());
    }

    public function testAJobWhoseImageExportsAVariableOfTheMarksNameStillCannotSeeTheMark(): void
    {
        $environment = [...$this->environment(null, (string) getenv('PATH')), 'intakt_mark' => 'from-image-env', 'mark' => 'from-image-env'];

        $run = $this->startCheckWith(['/missing', '--', '/bin/sh', '-c', 'env; printf "%s\n" "${intakt_mark-}" "${mark-}"'], $environment);

        self::assertSame(0, $run->getExitCode());
        self::assertStringContainsString(self::CHECK.'reduced mode: input', $run->getErrorOutput());
        self::assertStringNotContainsString(self::MARK, $run->getOutput());
    }

    public function testAJobWhoseImageRunsBashAsItsShellWithEveryVariableExportedStillCannotSeeTheMark(): void
    {
        $bash = new ExecutableFinder()->find('bash');

        if (null === $bash) {
            self::markTestSkipped('This case needs bash, because only bash reads SHELLOPTS from its environment.');
        }

        // Bash under the name sh, because the host's own /bin/sh may be a shell that ignores SHELLOPTS, unlike an image whose /bin/sh is bash.
        symlink($bash, $this->root.'/sh');
        $environment = [...$this->environment(null, (string) getenv('PATH')), 'SHELLOPTS' => 'allexport'];

        $run = $this->startCheckWith(['/missing', '--', '/bin/sh', '-c', 'env'], $environment, $this->root.'/sh');

        self::assertSame(0, $run->getExitCode());
        self::assertStringContainsString(self::CHECK.'reduced mode: input', $run->getErrorOutput());
        self::assertStringContainsString('SHELLOPTS=', $run->getOutput());
        self::assertStringNotContainsString(self::MARK, $run->getOutput());
    }

    public function testEveryLineOfTheCheckCarriesTheMark(): void
    {
        $run = $this->whenTheCheckRuns([$this->root.'/input']);

        self::assertSame(self::CHECK."output: ok\n".self::CHECK."work: ok\n".self::CHECK.'input: '.$this->root."/input: ok\n".self::CHECK."full mode\n", $run->getErrorOutput());
    }

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

    /**
     * @param list<string> $inputs
     */
    private function whenTheCheckRuns(array $inputs, ?string $egress = null, ?string $path = null): Process
    {
        return $this->startCheckWith(
            [...$inputs, '--', '/bin/sh', '-c', 'printf "%s" "${INTAKT_REDUCED_MODE-unset}"'],
            $this->environment($egress, $this->tools.':'.($path ?? (string) getenv('PATH'))),
        );
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

    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $environment
     */
    private function startCheckWith(array $arguments, array $environment, string $shell = '/bin/sh'): Process
    {
        $run = new Process([$shell, '-c', $this->script(), 'intakt-start-check', self::MARK, ...$arguments], null, $environment);
        $run->run();

        return $run;
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

    // Stripped, because every line of the check carries the prefix and the expected lines read clearer without it.
    private function logOf(Process $run): string
    {
        return str_replace(self::CHECK, '', $run->getErrorOutput());
    }
}
