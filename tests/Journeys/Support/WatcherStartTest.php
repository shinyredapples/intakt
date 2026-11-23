<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use DateInterval;
use DateTimeImmutable;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(WatcherStart::class)]
#[UsesClass(ContainerRebuild::class)]
#[UsesClass(SampleRepository::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class WatcherStartTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../fixtures/sample-repository';
    private const string ROUND = '0198a1b2-0000-7000-8000-000000000001';
    private const string OTHER_ROUND = '0198a1b2-0000-7000-8000-000000000002';
    private const string EARLIER_EVENT = '01a19b80-0000-7000-8000-000000000001';

    private string $directory = '';

    private string $head = '';

    /** @var list<string> */
    private array $ledgers = [];

    /** @var list<array{arguments: list<string>, environment: array<string,string>, seconds: int}> */
    private array $calls = [];

    public function testTheRecordingHoldsTheSamplePullRequestAndASuccessfulCiRunForItsHead(): void
    {
        $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head)));

        $recording = $this->recording();

        self::assertCount(1, $recording['pulls']);
        self::assertSame($this->head, $recording['pulls'][0]['head']['sha']);
        self::assertCount(1, $recording['workflow_runs']);
        self::assertSame(['head_sha' => $this->head, 'status' => 'completed', 'conclusion' => 'success'], array_intersect_key($recording['workflow_runs'][0], array_flip(['head_sha', 'status', 'conclusion'])));
    }

    public function testTheSampleProjectIsWatchedOnTheSampleRepositoryAndKeepsItsOtherSettings(): void
    {
        $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head)));

        /** @var array{projects: array<string, array<string, mixed>>} $written */
        $written = Yaml::parseFile($this->configuration());

        self::assertSame(['repository' => $this->directory.'/sample', 'base' => SampleRepository::BASE, 'task' => SampleProject::TASK], $written['projects'][SampleProject::NAME]['watch']);
        self::assertArrayHasKey('tiers', $written['projects'][SampleProject::NAME]);
    }

    public function testOneWatcherTickRunsWithTheRecordingForThatProcessOnly(): void
    {
        $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head)));

        $ticks = array_values(array_filter($this->calls, static fn (array $call): bool => 'messenger:consume' === $call['arguments'][0]));
        $recorded = array_filter($this->calls, static fn (array $call): bool => array_key_exists('WATCHER_RECORDING', $call['environment']));

        self::assertCount(1, $ticks);
        self::assertSame('scheduler_watcher', $ticks[0]['arguments'][1]);
        self::assertContains('--limit=1', $ticks[0]['arguments']);
        self::assertSame($this->recordingFile(), $ticks[0]['environment']['WATCHER_RECORDING']);
        self::assertCount(1, $recorded);
    }

    public function testTheTickWaitsLongerThanTheScheduleIntervalAndItsProcessLongerStill(): void
    {
        $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head)));

        $tick = array_find($this->calls, static fn (array $call): bool => 'messenger:consume' === $call['arguments'][0]) ?? self::fail('no tick ran');
        $limit = array_find($tick['arguments'], static fn (string $argument): bool => str_starts_with($argument, '--time-limit=')) ?? self::fail('the tick has no time limit');
        $seconds = (int) substr($limit, strlen('--time-limit='));
        $interval = new DateTimeImmutable('@0')->add(DateInterval::createFromDateString(WatcherSchedule::INTERVAL))->getTimestamp();

        self::assertGreaterThan($interval, $seconds);
        self::assertGreaterThan($seconds, $tick['seconds']);
    }

    public function testAConfigurationWithoutTheSampleProjectFailsNamingTheFile(): void
    {
        new Filesystem()->dumpFile($this->configuration(), Yaml::dump(['projects' => ['other' => []]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($this->configuration(), '/').' has no sample project/');

        $this->start($this->ledgerWith());
    }

    public function testTheContainerIsRebuiltOnTheWatchedConfigurationBeforeTheTick(): void
    {
        $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head)));

        self::assertSame(['cache:warmup', 'intakt:ledger:events', 'messenger:consume', 'intakt:ledger:events'], array_map(static fn (array $call): string => $call['arguments'][0], $this->calls));
        self::assertSame([$this->configuration()], array_unique(array_map(static fn (array $call): string => $call['environment']['INTAKT_CONFIG'] ?? '', $this->calls)));
    }

    public function testTheRoundIdComesFromTheRoundQueuedEntryForTheSampleHead(): void
    {
        $ledger = $this->ledgerWith(
            '2026-11-08T10:00:00Z 01a19b8a-30cb-7663-9685-c67965ea378e review_started {"round":"'.self::OTHER_ROUND.'","project":"sample"}',
            $this->queued(self::OTHER_ROUND, str_repeat('0', 40)),
            $this->queued(self::ROUND, $this->head),
        );

        self::assertSame(self::ROUND, $this->start($ledger));
    }

    public function testATickThatQueuedNoRoundFailsNamingTheRecording(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/recording '.preg_quote($this->recordingFile(), '/').' queued no round/');

        $this->start($this->ledgerWith('2026-11-08T10:00:00Z 01a19b8a-30ec-7ae5-ae69-65d774c10318 head_pending {"project":"sample","change":"1","head":"'.$this->head.'"}'));
    }

    public function testATickThatQueuedNoRoundFailsEvenWhenTheLedgerAlreadyHeldARoundForTheSampleHead(): void
    {
        $earlier = $this->queued(self::OTHER_ROUND, $this->head, self::EARLIER_EVENT);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/recording '.preg_quote($this->recordingFile(), '/').' queued no round/');

        $this->start($this->ledgerWith($earlier), before: $this->ledgerWith($earlier));
    }

    public function testTheRoundIdIsTheOneTheTickQueuedNotARoundTheLedgerAlreadyHeldForTheSampleHead(): void
    {
        $earlier = $this->queued(self::OTHER_ROUND, $this->head, self::EARLIER_EVENT);

        self::assertSame(self::ROUND, $this->start($this->ledgerWith($this->queued(self::ROUND, $this->head), $earlier), before: $this->ledgerWith($earlier)));
    }

    public function testARoundQueuedEntryWithoutARoundIdFailsInsteadOfReturningOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/round_queued entry of the ledger names no round/');

        $this->start($this->ledgerWith('2026-11-08T10:00:00Z 01a19b8a-30e4-7aa3-8780-141b1014c71a round_queued {"project":"sample","head":"'.$this->head.'"}'));
    }

    public function testARoundQueuedEntryThatIsNotJsonFailsInsteadOfBeingSkipped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/round_queued entry of the ledger is not JSON/');

        $this->start($this->ledgerWith('2026-11-08T10:00:00Z 01a19b8a-30e4-7aa3-8780-141b1014c71a round_queued {"project":"sample",'));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-watcher-start-'.bin2hex(random_bytes(6));
        new SampleRepository(self::FIXTURES)->buildOnce($this->directory.'/sample');
        $this->head = trim(new Process(['git', 'rev-parse', SampleRepository::BRANCH], $this->directory.'/sample')->mustRun()->getOutput());
        new Filesystem()->dumpFile($this->configuration(), Yaml::dump(['projects' => [SampleProject::NAME => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5']]]]], 6, 4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function queued(string $round, string $head, string $event = '01a19b8a-30e4-7aa3-8780-141b1014c71a'): string
    {
        return '2026-11-08T10:00:00Z '.$event.' round_queued {"project":"sample","change":"1","head":"'.$head.'","round":"'.$round.'"}';
    }

    private function ledgerWith(string ...$lines): string
    {
        return implode(PHP_EOL, [...$lines, count($lines).' events']).PHP_EOL;
    }

    private function start(string $ledger, string $before = "0 events\n"): string
    {
        $this->ledgers = [$before, $ledger];

        $console = $this->console(...);

        return new WatcherStart($console, new ContainerRebuild($this->directory.'/cache/container.php', $console))->startOnSamplePullRequest($this->directory.'/sample', $this->configuration());
    }

    /**
     * @param list<string>         $arguments
     * @param array<string,string> $environment
     */
    private function console(array $arguments, array $environment, int $seconds): string
    {
        $this->calls[] = ['arguments' => $arguments, 'environment' => $environment, 'seconds' => $seconds];

        return 'intakt:ledger:events' === $arguments[0] ? array_shift($this->ledgers) ?? '' : '';
    }

    /**
     * @return array{pulls: list<array{number: int, head: array{sha: string}, updated_at: string}>, workflow_runs: list<array<string, string|null>>}
     */
    private function recording(): array
    {
        /** @var array{pulls: list<array{number: int, head: array{sha: string}, updated_at: string}>, workflow_runs: list<array<string, string|null>>} $recording */
        $recording = json_decode((string) file_get_contents($this->recordingFile()), true, flags: JSON_THROW_ON_ERROR);

        return $recording;
    }

    private function configuration(): string
    {
        return $this->directory.'/intakt.yaml';
    }

    private function recordingFile(): string
    {
        return $this->directory.'/watcher-recording.json';
    }
}
