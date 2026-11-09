<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(JourneyAlarm::class)]
#[CoversClass(GitHubIssues::class)]
#[UsesClass(JourneyAlarmTask::class)]
#[UsesClass(JourneyRun::class)]
#[UsesClass(JourneyFailure::class)]
final class JourneyAlarmTest extends TestCase
{
    private const string COMMAND = __DIR__.'/../../../bin/journey-alarm';
    private const string REPOSITORY = 'example/repo';
    // Records each call with what it read on stdin, and answers from the issues file it keeps, so a later run sees an earlier run's issue.
    // With `listing-waits`, a listing waits up to 2 seconds for a second one, so two runs without serialization both list before either creates.
    // With `rival.json`, a create also opens that issue, as a run on another machine would at the same moment.
    private const string FAKE_GH = <<<'PHP'
        #!/usr/bin/env php
        <?php
        $arguments = array_slice($argv, 1);
        $action = 'api' === $arguments[0] ? 'api' : $arguments[1];
        $input = in_array('--body-file', $arguments, true) ? stream_get_contents(STDIN) : '';
        file_put_contents(__DIR__.'/calls.jsonl', json_encode(['arguments' => $arguments, 'input' => $input])."\n", FILE_APPEND | LOCK_EX);
        if ('api' === $action && is_file(__DIR__.'/listing-waits')) {
            touch(__DIR__.'/listed-'.getmypid());
            $end = microtime(true) + 2;
            while (count(glob(__DIR__.'/listed-*')) < 2 && microtime(true) < $end) {
                usleep(10000);
            }
        }
        $issues = json_decode(file_get_contents(__DIR__.'/issues.json'), true);
        if ('api' === $action) {
            echo is_array($issues) && array_is_list($issues) ? json_encode(array_chunk($issues, 100)) : json_encode($issues);
        }
        if ('create' === $action) {
            $number = count($issues) + 7;
            $issues[] = ['number' => $number, 'body' => $input];
            if (is_file(__DIR__.'/rival.json')) {
                $issues[] = json_decode(file_get_contents(__DIR__.'/rival.json'), true);
                unlink(__DIR__.'/rival.json');
            }
            echo 'https://github.com/example/repo/issues/'.$number."\n";
        }
        if ('edit' === $action) {
            $issues = array_map(fn (array $issue): array => $issue['number'] === (int) $arguments[2] ? ['number' => $issue['number'], 'body' => $input] : $issue, $issues);
            echo 'https://github.com/example/repo/issues/'.$arguments[2]."\n";
        }
        if ('close' === $action) {
            $issues = array_values(array_filter($issues, fn (array $issue): bool => $issue['number'] !== (int) $arguments[2]));
        }
        file_put_contents(__DIR__.'/issues.json', json_encode($issues));
        PHP;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-alarm-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/gh', self::FAKE_GH."\n");
        chmod($this->directory.'/gh', 0o755);
        $this->openIssues('[]');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testAFirstRedRunCreatesExactlyOneUrgentReadyTaskNamingTheJourneyStepAndTail(): void
    {
        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame("filed https://github.com/example/repo/issues/7\n", $alarm->getOutput(), $alarm->getErrorOutput());
        $create = $this->callsOf('create');
        self::assertCount(1, $create);
        self::assertSame(['issue', 'create', '--title', 'fix(journeys): journey "broken" fails on main', '--label', 'priority:urgent', '--label', 'ready', '--body-file', '-', '--repo', self::REPOSITORY], $create[0]['arguments']);
        self::assertStringContainsString('journey "broken" failed at step "read the second page": Failed asserting that two strings are identical.', $create[0]['input']);
        self::assertStringContainsString("```\n".trim((string) file_get_contents($this->fixture()))."\n```", $create[0]['input']);
    }

    public function testASecondRedRunEditsTheOpenTaskAndCreatesNone(): void
    {
        $this->alarm([$this->fixture()]);
        $second = (string) file_get_contents($this->fixture());
        $second = str_replace('read the second page', 'read the third page', $second);

        $alarm = $this->alarm(['--run=https://github.com/example/repo/actions/runs/2'], $second);

        self::assertSame("updated #7\n", $alarm->getOutput(), $alarm->getErrorOutput());
        self::assertCount(1, $this->callsOf('create'));
        $edit = $this->callsOf('edit');
        self::assertCount(1, $edit);
        self::assertSame(['issue', 'edit', '7', '--body-file', '-', '--repo', self::REPOSITORY], $edit[0]['arguments']);
        self::assertStringContainsString('failed at step "read the third page"', $edit[0]['input']);
        self::assertStringNotContainsString('read the second page', $edit[0]['input']);
        self::assertStringContainsString('Run: https://github.com/example/repo/actions/runs/2', $edit[0]['input']);
    }

    public function testTheOldestOfSeveralMarkedOpenIssuesIsUpdated(): void
    {
        $this->openIssues($this->issues([12, JourneyAlarmTask::MARKER."\nold"], [5, "first\r\n".JourneyAlarmTask::MARKER."\r\nold"]));

        self::assertSame("updated #5\n", $this->alarm([$this->fixture()])->getOutput());
    }

    public function testAnOpenIssueThatOnlyQuotesTheMarkerInsideALineIsNotTheTask(): void
    {
        $this->openIssues($this->issues([3, 'see '.JourneyAlarmTask::MARKER.' in the alarm']));

        self::assertSame("filed https://github.com/example/repo/issues/8\n", $this->alarm([$this->fixture()])->getOutput());
    }

    public function testAGreenRunFilesNothingAndCallsNoGh(): void
    {
        $alarm = $this->alarm([], JourneyRunTest::PASS);

        self::assertSame(0, $alarm->getExitCode());
        self::assertSame("no journey failed: nothing filed\n", $alarm->getOutput());
        self::assertSame([], $this->calls());
    }

    public function testOutputItCannotReadFailsWithAMessageAndCallsNoGh(): void
    {
        $alarm = $this->alarm([], "Fatal error: out of memory\n");

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('no failing journey and no pass', $alarm->getErrorOutput());
        self::assertSame([], $this->calls());
    }

    public function testAnAnswerOfGhThatIsNoIssueListFailsAndFilesNothing(): void
    {
        $this->openIssues('{"message":"Bad credentials"}');

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('not a list of issues', $alarm->getErrorOutput());
        self::assertSame([], $this->callsOf('create'));
    }

    public function testAnIssueOfGhWithoutABodyFailsAndFilesNothing(): void
    {
        $this->openIssues('[{"number":3}]');

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('not a list of issues', $alarm->getErrorOutput());
        self::assertSame([], $this->callsOf('create'));
    }

    public function testARedRunWithoutARepositoryFailsBeforeCallingGh(): void
    {
        $alarm = $this->alarm([$this->fixture()], '', '');

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('GITHUB_REPOSITORY must name the repository', $alarm->getErrorOutput());
        self::assertSame([], $this->calls());
    }

    public function testAFailingGhCallFailsTheCommand(): void
    {
        new Filesystem()->dumpFile($this->directory.'/gh', "#!/usr/bin/env sh\necho 'HTTP 401' >&2\nexit 1\n");

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('gh api repos/example/repo/issues?state=open&per_page=100 failed: HTTP 401', $alarm->getErrorOutput());
    }

    public function testTwoRedRunsAtTheSameTimeFileOneTaskAndTheLaterUpdatesIt(): void
    {
        new Filesystem()->touch($this->directory.'/listing-waits');
        $runs = [$this->started([$this->fixture()]), $this->started([$this->fixture()])];
        array_walk($runs, static fn (Process $run): int => $run->wait());

        $outputs = array_map(static fn (Process $run): string => $run->getOutput(), $runs);
        sort($outputs);
        self::assertSame(["filed https://github.com/example/repo/issues/7\n", "updated #7\n"], $outputs, $runs[0]->getErrorOutput().$runs[1]->getErrorOutput());
        self::assertCount(1, $this->callsOf('create'));
    }

    public function testARunThatMeetsAnOlderTaskFiledMeanwhileClosesItsOwnAndUpdatesTheOlder(): void
    {
        new Filesystem()->dumpFile($this->directory.'/rival.json', json_encode(['number' => 2, 'body' => JourneyAlarmTask::MARKER."\nrival"], JSON_THROW_ON_ERROR));

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame("updated #2, closed #7 as its duplicate\n", $alarm->getOutput(), $alarm->getErrorOutput());
        self::assertSame([['issue', 'close', '7', '--comment', 'Duplicate of #2', '--repo', self::REPOSITORY]], array_column($this->callsOf('close'), 'arguments'));
        self::assertSame(['issue', 'edit', '2', '--body-file', '-', '--repo', self::REPOSITORY], $this->callsOf('edit')[0]['arguments']);
    }

    public function testARunWhoseTaskIsOlderThanOneFiledMeanwhileKeepsIt(): void
    {
        new Filesystem()->dumpFile($this->directory.'/rival.json', json_encode(['number' => 9, 'body' => JourneyAlarmTask::MARKER."\nrival"], JSON_THROW_ON_ERROR));

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame("filed https://github.com/example/repo/issues/7\n", $alarm->getOutput(), $alarm->getErrorOutput());
        self::assertSame([], $this->callsOf('close'));
        self::assertSame([], $this->callsOf('edit'));
    }

    public function testAMarkedTaskBehindAThousandNewerOpenIssuesIsUpdated(): void
    {
        $newer = array_map(static fn (int $number): array => [$number, 'ordinary'], range(1002, 2));
        $this->openIssues($this->issues(...[...$newer, [1, JourneyAlarmTask::MARKER]]));

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame("updated #1\n", $alarm->getOutput(), $alarm->getErrorOutput());
        self::assertSame([], $this->callsOf('create'));
    }

    public function testAPullRequestWhoseBodyHoldsTheMarkerIsNotTheTask(): void
    {
        $this->openIssues(json_encode([['number' => 4, 'body' => JourneyAlarmTask::MARKER, 'pull_request' => ['url' => 'https://api.github.com/repos/example/repo/pulls/4']]], JSON_THROW_ON_ERROR));

        self::assertSame("filed https://github.com/example/repo/issues/8\n", $this->alarm([$this->fixture()])->getOutput());
    }

    public function testAnOpenIssueWithAnEmptyBodyIsNotTheTask(): void
    {
        $this->openIssues('[{"number":3,"body":null}]');

        self::assertSame("filed https://github.com/example/repo/issues/8\n", $this->alarm([$this->fixture()])->getOutput());
    }

    public function testARedRunWithoutATokenInTheEnvironmentFailsBeforeCallingGh(): void
    {
        $alarm = $this->alarm([$this->fixture()], environment: ['GH_TOKEN' => false]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('GH_TOKEN or GITHUB_TOKEN must hold the token', $alarm->getErrorOutput());
        self::assertSame([], $this->calls());
    }

    public function testATokenInGithubTokenAloneIsEnough(): void
    {
        $alarm = $this->alarm([$this->fixture()], environment: ['GH_TOKEN' => false, 'GITHUB_TOKEN' => bin2hex(random_bytes(8))]);

        self::assertSame("filed https://github.com/example/repo/issues/7\n", $alarm->getOutput(), $alarm->getErrorOutput());
    }

    public function testACreateAnswerThatIsNoIssueLinkFailsTheCommand(): void
    {
        new Filesystem()->dumpFile($this->directory.'/gh', "#!/usr/bin/env sh\nif [ \"\$1\" = api ]; then echo '[[]]'; else echo 'new issue'; fi\n");

        $alarm = $this->alarm([$this->fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('not a link to an issue', $alarm->getErrorOutput());
    }

    public function testAFileItCannotReadIsAUsageError(): void
    {
        $alarm = $this->alarm([$this->directory.'/missing.out']);

        self::assertSame(2, $alarm->getExitCode());
        self::assertStringContainsString('cannot read', $alarm->getErrorOutput());
    }

    public function testARunLinkThatIsNotHttpsIsAUsageError(): void
    {
        $alarm = $this->alarm(['--run=http://github.com/example/repo/actions/runs/2', $this->fixture()]);

        self::assertSame(2, $alarm->getExitCode());
        self::assertSame([], $this->calls());
    }

    private function fixture(): string
    {
        return JourneyRunTest::ONE_BROKEN;
    }

    /**
     * @param list<string>              $arguments
     * @param array<string, string|false> $environment
     */
    private function alarm(array $arguments, string $input = '', string $repository = self::REPOSITORY, array $environment = []): Process
    {
        $process = $this->started($arguments, $input, $repository, $environment);
        $process->wait();

        return $process;
    }

    /**
     * @param list<string>              $arguments
     * @param array<string, string|false> $environment
     */
    private function started(array $arguments, string $input = '', string $repository = self::REPOSITORY, array $environment = []): Process
    {
        $process = new Process(
            [PHP_BINARY, self::COMMAND, ...$arguments],
            null,
            [
                'PATH' => $this->directory.':'.getenv('PATH'),
                'GITHUB_REPOSITORY' => $repository,
                'GH_TOKEN' => bin2hex(random_bytes(8)),
                'GITHUB_TOKEN' => false,
                'TMPDIR' => $this->directory,
                ...$environment,
            ],
            $input,
            60,
        );
        $process->start();

        return $process;
    }

    private function openIssues(string $json): void
    {
        new Filesystem()->dumpFile($this->directory.'/issues.json', $json);
    }

    /**
     * @param array{int, string} ...$issues
     */
    private function issues(array ...$issues): string
    {
        return json_encode(array_map(static fn (array $issue): array => ['number' => $issue[0], 'body' => $issue[1]], $issues), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{arguments: list<string>, input: string}>
     */
    private function callsOf(string $action): array
    {
        return array_values(array_filter($this->calls(), static fn (array $call): bool => $action === ('api' === $call['arguments'][0] ? 'api' : $call['arguments'][1] ?? null)));
    }

    /**
     * @return list<array{arguments: list<string>, input: string}>
     */
    private function calls(): array
    {
        $file = $this->directory.'/calls.jsonl';

        if (!is_file($file)) {
            return [];
        }

        return array_map($this->callFrom(...), (array) file($file, FILE_IGNORE_NEW_LINES));
    }

    /**
     * @return array{arguments: list<string>, input: string}
     */
    private function callFrom(mixed $line): array
    {
        self::assertIsString($line);
        $call = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($call);
        $arguments = $call['arguments'] ?? null;
        $input = $call['input'] ?? null;
        self::assertIsArray($arguments);
        self::assertIsString($input);

        return ['arguments' => array_values(array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : self::fail('a recorded argument is no string'), $arguments)), 'input' => $input];
    }
}
