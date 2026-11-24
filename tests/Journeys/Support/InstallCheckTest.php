<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(InstallCheck::class)]
#[CoversClass(InstallReport::class)]
#[CoversClass(InstallStepResult::class)]
#[CoversClass(InstallCheckAlarm::class)]
#[UsesClass(ReadmeInstallBlock::class)]
#[UsesClass(GitHubIssues::class)]
#[UsesClass(JourneyAlarmTask::class)]
final class InstallCheckTest extends TestCase
{
    private const string README = "<!-- install-check -->\n```\ngit clone https://example.invalid/x.git && cd x\ndocker compose up -d --wait\n```\n";

    private string $directory = '';

    /** @var list<string> */
    private array $calls = [];

    /** @var list<array{arguments: list<string>, input: string}> */
    private array $github = [];

    public function testABrokenInstallCommandFailsAtTheStepThatNamesItAndTheAlarmGetsTheStepAndItsLogTail(): void
    {
        $report = $this->check(['docker compose up -d --wait' => [1, "pulling\nno such image: intakt"]])->run('/some/repository');

        $steps = array_column(array_map(static fn (InstallStepResult $step): array => ['name' => $step->name, 'status' => $step->status->value], $report->steps), 'status', 'name');
        self::assertSame('failed', $steps['install: docker compose up -d --wait']);
        self::assertSame('skipped', $steps['demo: play the demo run to its verdict']);
        self::assertTrue($report->hasFailed());

        $answer = $this->alarm(InstallReport::fromJson($report->toJson()));

        self::assertStringStartsWith('filed https://github.com/example/repo/issues/', $answer);
        self::assertCount(1, $this->github);
        self::assertStringContainsString('`install: docker compose up -d --wait`', $this->github[0]['input']);
        self::assertStringContainsString("pulling\nno such image: intakt", $this->github[0]['input']);
    }

    public function testAPassingStepClosesItsOpenIssueAndASkippedStepLeavesItAlone(): void
    {
        $failed = new InstallReport([new InstallStepResult('install: docker compose up -d --wait', InstallStatus::Failed, 'boom')]);
        $this->alarm($failed);
        $open = $this->github[0]['input'];

        $passed = new InstallReport([new InstallStepResult('install: docker compose up -d --wait', InstallStatus::Passed, ''), new InstallStepResult('journey-check', InstallStatus::Skipped, 'none')]);

        self::assertSame('closed #7 (install: docker compose up -d --wait)', $this->alarm($passed, [['number' => 7, 'body' => $open]]));
        self::assertSame('no install step failed: nothing filed', $this->alarm($passed));
    }

    public function testASecondFailureOfTheSameStepUpdatesTheOpenIssue(): void
    {
        $failed = new InstallReport([new InstallStepResult('journey-check', InstallStatus::Failed, 'boom')]);
        $this->alarm($failed);
        $open = $this->github[0]['input'];

        self::assertSame('updated #7', $this->alarm($failed, [['number' => 7, 'body' => $open]]));
    }

    public function testAReportThatIsNotAListOfNamedStepsIsRefused(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        InstallReport::fromJson('{"steps":[{"name":"","status":"passed","tail":""}]}');
    }

    public function testTheKeyedStepIsSkippedWithoutAKey(): void
    {
        $report = $this->check()->run('/some/repository');

        $keyed = array_values(array_filter($report->steps, static fn (InstallStepResult $step): bool => str_starts_with($step->name, 'keyed')));
        self::assertSame(InstallStatus::Skipped, $this->stepStatus($keyed));
        self::assertSame([], array_filter($this->calls, static fn (string $call): bool => str_contains($call, 'intakt:demo')));
    }

    public function testAKeyInTheEnvironmentNeverAppearsInTheLogsTheReportOrACommandLine(): void
    {
        $key = 'stand-in-'.bin2hex(random_bytes(8));

        $report = $this->check(['intakt:demo' => [1, 'the provider refused '.$key]], ['OPENAI_API_KEY' => $key])->run('/some/repository');

        $everything = $report->toJson().implode("\n", $this->calls);
        foreach ((array) glob($this->directory.'/logs/*.log') as $log) {
            $everything .= file_get_contents((string) $log);
        }

        self::assertStringNotContainsString($key, $everything);
        self::assertContains('docker compose exec -T -e OPENAI_API_KEY intakt php bin/console intakt:demo', $this->calls);
        self::assertStringContainsString('[redacted]', $report->toJson());
    }

    public function testACloneWithoutJourneyCheckReportsThatStepAsSkippedNotFailed(): void
    {
        $report = $this->check()->run('/some/repository');

        $step = array_values(array_filter($report->steps, static fn (InstallStepResult $step): bool => 'journey-check' === $step->name));
        self::assertSame(InstallStatus::Skipped, $this->stepStatus($step));
        self::assertFalse($report->hasFailed());
    }

    public function testTheContainersAreStoppedAndTheCloneRemovedAfterAFailingStep(): void
    {
        $this->check(['docker compose up -d --wait' => [1, 'boom']])->run('/some/repository');

        self::assertSame('docker compose down -v --remove-orphans', array_last($this->calls));
        self::assertDirectoryDoesNotExist($this->directory.'/clone');
    }

    public function testAReadmeWithoutTheMarkerCouldNotBeCheckedYetStillTearsDown(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        try {
            $this->check([], [], "```\nls\n```\n")->run('/some/repository');
        } finally {
            self::assertSame('docker compose down -v --remove-orphans', array_last($this->calls));
            self::assertDirectoryDoesNotExist($this->directory.'/clone');
        }
    }

    public function testAFailedCloneOrMissingDockerOrDashedAddressCouldNotRun(): void
    {
        foreach ([['docker compose version' => [1, '']], ['git clone' => [128, 'fatal: not found']]] as $answers) {
            try {
                $this->check($answers)->run('/some/repository');
                self::fail('the check should not have run');
            } catch (InstallCheckCouldNotRun) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InstallCheckCouldNotRun::class);
        $this->check()->run('--upload-pack=x');
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-install-check-test-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @param list<InstallStepResult> $steps
     */
    private function stepStatus(array $steps): InstallStatus
    {
        return (array_first($steps) ?? throw new LogicException('no such step'))->status;
    }

    /**
     * @param array<string, array{0: int, 1: string}> $answers
     * @param array<string, string>                   $environment
     */
    private function check(array $answers = [], array $environment = [], string $readme = self::README): InstallCheck
    {
        $runner = function (string $command, string $directory) use ($answers, $readme): array {
            $this->calls[] = $command;

            if (str_starts_with($command, 'git clone') && !isset($answers['git clone'])) {
                new Filesystem()->dumpFile($this->directory.'/clone/README.md', $readme);
            }

            foreach ($answers as $part => $answer) {
                if (str_contains($command, $part)) {
                    return $answer;
                }
            }

            return [0, 'ok'];
        };

        return new InstallCheck($runner, $this->directory, $this->directory.'/logs', $this->directory.'/checkout', $environment);
    }

    /**
     * @param list<array{number: int, body: string}> $open
     */
    private function alarm(InstallReport $report, array $open = []): string
    {
        $this->github = [];
        $runner = function (array $arguments, string $input) use ($open): string {
            if ('api' === $arguments[0]) {
                return json_encode([$open], JSON_THROW_ON_ERROR);
            }

            $this->github[] = ['arguments' => array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', array_values($arguments)), 'input' => $input];

            return 'create' === $arguments[1] ? 'https://github.com/example/repo/issues/7' : '';
        };
        putenv('GH_TOKEN=stand-in');

        try {
            return new InstallCheckAlarm(new GitHubIssues('example/repo', $runner), new LockFactory(new InMemoryStore()))->raise($report, null);
        } finally {
            putenv('GH_TOKEN');
        }
    }
}
