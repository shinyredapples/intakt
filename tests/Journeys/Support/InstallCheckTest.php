<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(InstallCheck::class)]
#[CoversClass(InstallReport::class)]
#[CoversClass(InstallStepResult::class)]
#[CoversClass(InstallCheckAlarm::class)]
#[CoversClass(InstallWalk::class)]
#[UsesClass(ReadmeInstallBlock::class)]
#[UsesClass(InstallProcessRunner::class)]
#[UsesClass(GitHubIssues::class)]
#[UsesClass(JourneyAlarmTask::class)]
final class InstallCheckTest extends TestCase
{
    private const string CLONE_COMMAND = 'git clone https://github.com/shinyredapples/intakt.git && cd intakt';
    private const string README = "<!-- install-check -->\n```\n".self::CLONE_COMMAND."\ndocker compose up -d --wait\n```\n";

    private string $directory = '';

    /** @var list<array{command: string, directory: string, environment: array<string, string>}> */
    private array $calls = [];

    /** @var list<array{arguments: list<string>, input: string}> */
    private array $github = [];

    private bool $withJourneyCheck = false;

    private string $keptEnvironmentFile = '';

    /** @var array<string, array{0: int, 1: string}> */
    private array $answers = [];

    private string $readme = '';

    /** @var list<array{number: int, body: string}> */
    private array $open = [];

    /** @var list<array{number: int, body: string}>|null */
    private ?array $afterCreate = null;

    public function testABrokenInstallCommandFailsAtTheStepThatNamesItAndTheAlarmGetsTheStepAndItsLogTail(): void
    {
        $report = $this->check(['docker compose up -d --wait' => [1, "pulling\nno such image: intakt"]])->run('/some/repository');

        $steps = $this->statuses($report);
        self::assertSame('passed', $steps['install: '.self::CLONE_COMMAND]);
        self::assertSame('failed', $steps['install: docker compose up -d --wait']);
        self::assertSame('skipped', $steps['demo: play the demo run to its verdict']);
        self::assertTrue($report->hasFailed());

        $answer = $this->alarm(InstallReport::fromJson($report->toJson()));

        self::assertStringStartsWith('filed https://github.com/example/repo/issues/', $answer);
        self::assertCount(1, $this->github);
        self::assertStringContainsString('`install: docker compose up -d --wait`', $this->github[0]['input']);
        self::assertStringContainsString("pulling\nno such image: intakt", $this->github[0]['input']);
    }

    public function testTheReadmeCloneCommandRunsVerbatimSoABrokenAddressFailsItsStep(): void
    {
        $report = $this->check(['git clone https://github.com/shinyredapples/intakt.git' => [128, 'fatal: repository not found']])->run('/some/repository');

        self::assertSame('failed', $this->statuses($report)['install: '.self::CLONE_COMMAND]);
        self::assertSame('skipped', $this->statuses($report)['install: docker compose up -d --wait']);
    }

    public function testTheReadmeAddressIsRewrittenToTheRepositoryUnderCheck(): void
    {
        $this->check()->run('/some/repository');

        $clone = $this->callOf(self::CLONE_COMMAND);
        self::assertSame($this->directory.'/home', $clone['directory']);
        self::assertSame('url./some/repository.insteadOf', $clone['environment']['GIT_CONFIG_KEY_0'] ?? null);
        self::assertSame(InstallCheck::REPOSITORY, $clone['environment']['GIT_CONFIG_VALUE_0'] ?? null);
        self::assertSame($this->directory.'/home/intakt', $this->callOf('docker compose up -d --wait')['directory']);
    }

    public function testARelativeRepositoryPathIsClonedFromWhereTheCallerStands(): void
    {
        new Filesystem()->mkdir($this->directory.'/repository');

        $this->check()->run(Path::makeRelative($this->directory.'/repository', (string) getcwd()));

        self::assertStringContainsString(escapeshellarg((string) realpath($this->directory.'/repository')), $this->callOf('git clone --')['command']);
    }

    public function testAnInstallCommandStartsWhereTheOneBeforeItsCdEnded(): void
    {
        $home = $this->directory.'/home';
        new Filesystem()->mkdir($home);
        $walk = new InstallWalk($this->shellButNoContainers(...), [], $this->directory.'/logs', $this->directory.'/checkout', [], new Filesystem());

        $report = $walk->through($home, ['mkdir -p intakt/subdir && cd intakt/subdir', 'test -d ../subdir && test "$(pwd)" = "$(cd '.escapeshellarg($home).' && pwd)/intakt/subdir"']);

        self::assertSame(['passed', 'passed'], array_slice(array_values($this->statuses($report)), 0, 2));
    }

    public function testAStepTheReadmeNoLongerHasClosesItsIssueOnceTheInstallPasses(): void
    {
        $this->alarm(new InstallReport([new InstallStepResult('install: one', InstallStatus::Passed, ''), new InstallStepResult('install: two', InstallStatus::Failed, 'boom')]));
        $open = $this->github[0]['input'];

        $answer = $this->alarm(new InstallReport([new InstallStepResult('install: one', InstallStatus::Passed, '')]), [['number' => 7, 'body' => $open]]);

        self::assertSame('closed #7 (a step this run no longer has)', $answer);
        self::assertSame(['issue', 'close', '7', '--comment', 'The install check passes and no longer has this step.', '--repo', 'example/repo'], $this->github[0]['arguments']);
    }

    public function testAPassingRunClosesNoIssueThatOnlyQuotesAMarker(): void
    {
        $owned = '<!-- intakt:install-check:'.hash('sha256', 'install: #2').' -->';
        $open = [
            ['number' => 7, 'body' => $owned."\nfiled by the alarm"],
            ['number' => 8, 'body' => "Unrelated issue showing an example marker:\n<!-- intakt:install-check:<hash> -->"],
            ['number' => 9, 'body' => "Quoting a real one:\n".$owned],
            ['number' => 10, 'body' => '<!-- intakt:install-check:<hash> -->'],
        ];

        $answer = $this->alarm(new InstallReport([new InstallStepResult('install: one', InstallStatus::Passed, '')]), $open);

        self::assertSame('closed #7 (a step this run no longer has)', $answer);
    }

    public function testTeardownStopsTheContainersWhereComposeRanAfterALaterLineLeftTheClone(): void
    {
        $home = $this->directory.'/home';
        new Filesystem()->mkdir($home);
        $walk = new InstallWalk($this->composeOnlyBesideItsFile(...), [], $this->directory.'/logs', $this->directory.'/checkout', [], new Filesystem());

        $report = $walk->through($home, ['mkdir -p intakt && touch intakt/compose.yaml && cd intakt', 'docker compose up -d --wait', 'cd .. && false']);

        self::assertSame('passed', $this->statuses($report)['teardown: stop the containers and remove their volume']);
        self::assertSame($home.'/intakt', $this->callOf('docker compose down')['directory']);
    }

    public function testAStepMissingFromAFailedInstallKeepsItsIssueOpen(): void
    {
        $this->alarm(new InstallReport([new InstallStepResult('install: one', InstallStatus::Passed, ''), new InstallStepResult('install: two', InstallStatus::Failed, 'boom')]));
        $open = $this->github[0]['input'];

        $answer = $this->alarm(new InstallReport([new InstallStepResult('install: one', InstallStatus::Failed, 'other')]), [['number' => 7, 'body' => $open]]);

        self::assertStringStartsWith('filed ', $answer);
        self::assertSame([], array_filter($this->github, static fn (array $call): bool => 'close' === $call['arguments'][1]));
    }

    public function testEachRunUsesItsOwnComposeProject(): void
    {
        $this->check()->run('/some/repository');
        $first = $this->callOf('docker compose down')['environment']['COMPOSE_PROJECT_NAME'] ?? '';
        $this->calls = [];
        $this->check()->run('/some/repository');

        self::assertStringStartsWith('intakt-install-check-', $first);
        self::assertNotSame($first, $this->callOf('docker compose down')['environment']['COMPOSE_PROJECT_NAME'] ?? '');
    }

    public function testAFailedTeardownFailsTheCheck(): void
    {
        $report = $this->check(['docker compose down' => [1, 'cannot stop']])->run('/some/repository');

        self::assertSame('failed', $this->statuses($report)['teardown: stop the containers and remove their volume']);
        self::assertTrue($report->hasFailed());
    }

    public function testTheJourneyCheckStepGivesTheDashboardAddress(): void
    {
        $this->withJourneyCheck = true;

        $report = $this->check()->run('/some/repository');

        self::assertSame('passed', $this->statuses($report)['journey-check']);
        self::assertSame('node bin/journey-check '.escapeshellarg(InstallCheck::ADDRESS), $this->callOf('bin/journey-check')['command']);
    }

    public function testJourneyAndUiScreenshotsAreKeptWithTheLogs(): void
    {
        $this->withJourneyCheck = true;

        $this->check()->run('/some/repository');

        self::assertFileExists($this->directory.'/logs/ui-check-start-light-390.png');
        self::assertFileExists($this->directory.'/logs/run-1-01-first-install.png');
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

    public function testAFixedReadmeCommandClosesTheIssueItsBrokenSpellingFiled(): void
    {
        $this->alarm(new InstallReport([new InstallStepResult('install: docker compsoe up -d --wait', InstallStatus::Failed, 'typo')]));
        $open = $this->github[0]['input'];

        $fixed = new InstallReport([new InstallStepResult('install: docker compose up -d --wait', InstallStatus::Passed, '')]);

        self::assertSame('closed #7 (install: docker compose up -d --wait)', $this->alarm($fixed, [['number' => 7, 'body' => $open]]));
    }

    public function testASecondFailureOfTheSameStepUpdatesTheOpenIssue(): void
    {
        $failed = new InstallReport([new InstallStepResult('journey-check', InstallStatus::Failed, 'boom')]);
        $this->alarm($failed);
        $open = $this->github[0]['input'];

        self::assertSame('updated #7', $this->alarm($failed, [['number' => 7, 'body' => $open]]));
    }

    public function testAnIssueFiledMeanwhileByAnotherRunKeepsTheOlderOneAndClosesTheNewAsItsDuplicate(): void
    {
        $failed = new InstallReport([new InstallStepResult('journey-check', InstallStatus::Failed, 'boom')]);
        $this->alarm($failed);
        $marker = explode("\n", $this->github[0]['input'])[0];

        self::assertSame('updated #3, closed #7 as its duplicate', $this->alarm($failed, [], [['number' => 3, 'body' => $marker."\nrival"]]));
        self::assertSame(['issue', 'close', '7', '--comment', 'Duplicate of #3', '--repo', 'example/repo'], $this->github[1]['arguments']);
    }

    public function testAReportThatIsNotAListOfNamedStepsIsRefused(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        InstallReport::fromJson('{"steps":[{"name":"","status":"passed","tail":""}]}');
    }

    public function testTheKeyedStepIsSkippedWithoutAKey(): void
    {
        $report = $this->check()->run('/some/repository');

        self::assertSame('skipped', $this->statuses($report)['keyed: review the sample with a provider key']);
        self::assertSame([], array_filter($this->calls, static fn (array $call): bool => str_contains($call['command'], 'intakt:demo')));
    }

    public function testTheKeyGoesToTheCloneEnvLocalAndTheContainerStartsAgainBeforeTheReview(): void
    {
        $key = 'stand-in-'.bin2hex(random_bytes(8));

        $report = $this->check([], ['ANTHROPIC_API_KEY' => $key])->run('/some/repository');

        self::assertSame('passed', $this->statuses($report)['keyed: review the sample with a provider key']);
        self::assertSame('docker compose up -d --wait && docker compose exec -T intakt php bin/console intakt:demo', $this->callOf('intakt:demo')['command']);
        self::assertSame('ANTHROPIC_API_KEY='.$key."\n", $this->keptEnvironmentFile);
    }

    public function testAKeyInTheEnvironmentNeverAppearsInTheLogsTheReportACommandLineOrAStepEnvironment(): void
    {
        $key = 'stand-in-'.bin2hex(random_bytes(8));

        $report = $this->check(['intakt:demo' => [1, 'the provider refused '.$key]], ['OPENAI_API_KEY' => $key])->run('/some/repository');

        $everything = $report->toJson().json_encode($this->calls, JSON_THROW_ON_ERROR).$this->logs();

        self::assertStringNotContainsString($key, $everything);
        self::assertStringContainsString('[redacted]', $report->toJson());
    }

    public function testTheOwnerTokenIsRedactedAndAChangesVerdictCountsAsACompletedReview(): void
    {
        $token = 'stand-in-'.bin2hex(random_bytes(8));
        $line = 'Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer '.$token.'"';

        $report = $this->check(['intakt:demo' => [10, $line]], ['OPENAI_API_KEY' => 'stand-in-'.bin2hex(random_bytes(8))])->run('/some/repository');

        self::assertStringNotContainsString($token, $report->toJson().$this->logs());
        self::assertSame('passed', $this->statuses($report)['keyed: review the sample with a provider key']);
        self::assertFalse($report->hasFailed());
    }

    public function testACloneWithoutJourneyCheckReportsThatStepAsSkippedNotFailed(): void
    {
        $report = $this->check()->run('/some/repository');

        self::assertSame('skipped', $this->statuses($report)['journey-check']);
        self::assertFalse($report->hasFailed());
    }

    public function testTheContainersAreStoppedAndTheClonesRemovedAfterAFailingStep(): void
    {
        $this->check(['docker compose up -d --wait' => [1, 'boom']])->run('/some/repository');

        self::assertSame('docker compose down -v --remove-orphans', array_last($this->calls)['command'] ?? null);
        self::assertDirectoryDoesNotExist($this->directory.'/home');
        self::assertDirectoryDoesNotExist($this->directory.'/source');
    }

    public function testAReadmeWithoutTheMarkerCouldNotBeCheckedAndLeavesNoFolder(): void
    {
        try {
            $this->check([], [], "```\nls\n```\n")->run('/some/repository');
            self::fail('the check should not have run');
        } catch (InstallCheckCouldNotRun) {
            self::assertDirectoryDoesNotExist($this->directory.'/source');
        }
    }

    public function testAFailedCloneOrMissingDockerOrDashedAddressCouldNotRun(): void
    {
        foreach ([['docker compose version' => [1, '']], ['git clone --' => [128, 'fatal: not found']]] as $answers) {
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
     * @param array<string, array{0: int, 1: string}> $answers
     * @param array<string, string>                   $keys
     */
    private function check(array $answers = [], array $keys = [], string $readme = self::README): InstallCheck
    {
        $this->answers = $answers;
        $this->readme = $readme;

        return new InstallCheck($this->fakeRun(...), $this->directory, $this->directory.'/logs', $this->directory.'/checkout', $keys);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    private function fakeRun(string $command, string $directory, array $environment): array
    {
        $this->calls[] = ['command' => $command, 'directory' => $directory, 'environment' => $environment];
        $answer = array_find($this->answers, static fn (array $answer, string $part): bool => str_contains($command, $part)) ?? $this->madeBy($command, $directory);

        // No real shell runs here, so the fake writes the folder the README's cd would have ended in, because the walk reads it for the next command.
        if (0 === $answer[0] && isset($environment['INTAKT_INSTALL_CHECK_ENDED_IN'])) {
            new Filesystem()->dumpFile($environment['INTAKT_INSTALL_CHECK_ENDED_IN'], str_starts_with($command, self::CLONE_COMMAND."\n") ? $directory.'/intakt' : $directory);
        }

        return $answer;
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    private function shellButNoContainers(string $command, string $directory, array $environment): array
    {
        return str_starts_with($command, 'docker ') || str_starts_with($command, 'node ') ? [0, ''] : new InstallProcessRunner(10)($command, $directory, $environment);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    private function composeOnlyBesideItsFile(string $command, string $directory, array $environment): array
    {
        if (!str_starts_with($command, 'docker ')) {
            return new InstallProcessRunner(10)($command, $directory, $environment);
        }

        $this->calls[] = ['command' => $command, 'directory' => $directory, 'environment' => $environment];

        return is_file($directory.'/compose.yaml') ? [0, ''] : [1, 'no compose file'];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function madeBy(string $command, string $directory): array
    {
        $made = match (true) {
            str_starts_with($command, 'git clone --') => [$this->directory.'/source/README.md', $this->readme],
            str_starts_with($command, self::CLONE_COMMAND."\n") => [$directory.'/intakt/'.($this->withJourneyCheck ? 'bin/journey-check' : 'README.md'), ''],
            str_contains($command, 'bin/ui-check') => [$directory.'/var/ui-check/start-light-390.png', 'png'],
            str_contains($command, 'bin/journey-check') => [$directory.'/var/journey-check/run-1/01-first-install.png', 'png'],
            default => null,
        };

        if (null !== $made) {
            new Filesystem()->dumpFile(...$made);
        }

        if (str_contains($command, 'intakt:demo')) {
            $this->keptEnvironmentFile = (string) file_get_contents($directory.'/.env.local');
        }

        return [0, 'ok'];
    }

    /**
     * @return array<string, string>
     */
    private function statuses(InstallReport $report): array
    {
        return array_column(array_map(static fn (InstallStepResult $step): array => ['name' => $step->name, 'status' => $step->status->value], $report->steps), 'status', 'name');
    }

    /**
     * @param list<array{number: int, body: string}>      $open
     * @param list<array{number: int, body: string}>|null $afterCreate
     */
    private function alarm(InstallReport $report, array $open = [], ?array $afterCreate = null): string
    {
        $this->github = [];
        $this->open = $open;
        $this->afterCreate = $afterCreate;
        putenv('GH_TOKEN=stand-in');

        try {
            return new InstallCheckAlarm(new GitHubIssues('example/repo', $this->fakeGitHub(...)), new LockFactory(new InMemoryStore()))->raise($report, null);
        } finally {
            putenv('GH_TOKEN');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function fakeGitHub(array $arguments, string $input): string
    {
        if ('api' === $arguments[0]) {
            $created = [] !== array_filter($this->github, static fn (array $call): bool => 'create' === $call['arguments'][1]);

            return json_encode([$created ? $this->afterCreate ?? $this->open : $this->open], JSON_THROW_ON_ERROR);
        }

        $this->github[] = ['arguments' => $arguments, 'input' => $input];

        return 'create' === $arguments[1] ? 'https://github.com/example/repo/issues/7' : '';
    }

    /**
     * @return array{command: string, directory: string, environment: array<string, string>}
     */
    private function callOf(string $part): array
    {
        return array_find($this->calls, static fn (array $call): bool => str_contains($call['command'], $part)) ?? throw new LogicException('no call with '.$part);
    }

    private function logs(): string
    {
        $logs = '';

        foreach ((array) glob($this->directory.'/logs/*.log') as $log) {
            $logs .= file_get_contents((string) $log);
        }

        return $logs;
    }
}
