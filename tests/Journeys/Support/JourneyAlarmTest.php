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
    private const string FAKE_GH = <<<'PHP'
        #!/usr/bin/env php
        <?php
        $arguments = array_slice($argv, 1);
        $input = in_array('--body-file', $arguments, true) ? stream_get_contents(STDIN) : '';
        file_put_contents(__DIR__.'/calls.jsonl', json_encode(['arguments' => $arguments, 'input' => $input])."\n", FILE_APPEND);
        $issues = json_decode(file_get_contents(__DIR__.'/issues.json'), true);
        if ('list' === $arguments[1]) {
            echo file_get_contents(__DIR__.'/issues.json');
        }
        if ('create' === $arguments[1]) {
            $issues[] = ['number' => count($issues) + 7, 'body' => $input];
            echo 'https://github.com/example/repo/issues/'.(count($issues) + 6)."\n";
        }
        if ('edit' === $arguments[1]) {
            $issues = array_map(fn (array $issue): array => $issue['number'] === (int) $arguments[2] ? ['number' => $issue['number'], 'body' => $input] : $issue, $issues);
            echo 'https://github.com/example/repo/issues/'.$arguments[2]."\n";
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
        $alarm = $this->alarm([self::fixture()]);

        self::assertSame("filed https://github.com/example/repo/issues/7\n", $alarm->getOutput(), $alarm->getErrorOutput());
        $create = $this->callsOf('create');
        self::assertCount(1, $create);
        self::assertSame(['issue', 'create', '--title', 'fix(journeys): journey "broken" fails on main', '--label', 'priority:urgent', '--label', 'ready', '--body-file', '-', '--repo', self::REPOSITORY], $create[0]['arguments']);
        self::assertStringContainsString('journey "broken" failed at step "read the second page": Failed asserting that two strings are identical.', $create[0]['input']);
        self::assertStringContainsString("```\n".trim((string) file_get_contents(self::fixture()))."\n```", $create[0]['input']);
    }

    public function testASecondRedRunEditsTheOpenTaskAndCreatesNone(): void
    {
        $this->alarm([self::fixture()]);
        $second = (string) file_get_contents(self::fixture());
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

        self::assertSame("updated #5\n", $this->alarm([self::fixture()])->getOutput());
    }

    public function testAnOpenIssueThatOnlyQuotesTheMarkerInsideALineIsNotTheTask(): void
    {
        $this->openIssues($this->issues([3, 'see '.JourneyAlarmTask::MARKER.' in the alarm']));

        self::assertSame("filed https://github.com/example/repo/issues/8\n", $this->alarm([self::fixture()])->getOutput());
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

        $alarm = $this->alarm([self::fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('not a list of issues', $alarm->getErrorOutput());
        self::assertSame([], $this->callsOf('create'));
    }

    public function testARedRunWithoutARepositoryFailsBeforeCallingGh(): void
    {
        $alarm = $this->alarm([self::fixture()], '', '');

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('GITHUB_REPOSITORY must name the repository', $alarm->getErrorOutput());
        self::assertSame([], $this->calls());
    }

    public function testAFailingGhCallFailsTheCommand(): void
    {
        new Filesystem()->dumpFile($this->directory.'/gh', "#!/usr/bin/env sh\necho 'HTTP 401' >&2\nexit 1\n");

        $alarm = $this->alarm([self::fixture()]);

        self::assertSame(1, $alarm->getExitCode());
        self::assertStringContainsString('gh issue list failed: HTTP 401', $alarm->getErrorOutput());
    }

    public function testAFileItCannotReadIsAUsageError(): void
    {
        $alarm = $this->alarm([$this->directory.'/missing.out']);

        self::assertSame(2, $alarm->getExitCode());
        self::assertStringContainsString('cannot read', $alarm->getErrorOutput());
    }

    public function testARunLinkThatIsNotHttpsIsAUsageError(): void
    {
        $alarm = $this->alarm(['--run=http://github.com/example/repo/actions/runs/2', self::fixture()]);

        self::assertSame(2, $alarm->getExitCode());
        self::assertSame([], $this->calls());
    }

    private static function fixture(): string
    {
        return JourneyRunTest::ONE_BROKEN;
    }

    /**
     * @param list<string> $arguments
     */
    private function alarm(array $arguments, string $input = '', string $repository = self::REPOSITORY): Process
    {
        $process = new Process(
            [PHP_BINARY, self::COMMAND, ...$arguments],
            null,
            ['PATH' => $this->directory.':'.getenv('PATH'), 'GITHUB_REPOSITORY' => $repository],
            $input,
            60,
        );
        $process->run();

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
        return array_values(array_filter($this->calls(), static fn (array $call): bool => $action === ($call['arguments'][1] ?? null)));
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

        /** @var list<array{arguments: list<string>, input: string}> */
        return array_map(static fn (string $line): array => (array) json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES));
    }
}
