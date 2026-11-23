<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherSchedule;
use JsonException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

// Starts a round the way a user's pull request does: one watcher tick over a recorded forge, so a journey covers the watcher's path into the review and not a shortcut past it.
final readonly class WatcherStart
{
    private const string RECORDING = 'watcher-recording.json';
    private const string CONFIGURATION_VARIABLE = 'INTAKT_CONFIG';
    // A recording instead of a forge, because the test environment binds the forge's listing and CI reads to tests/Core/Watcher/RecordedPullRequests.php, which reads this variable.
    private const string RECORDING_VARIABLE = 'WATCHER_RECORDING';
    private const int PULL_REQUEST = 1;
    // The sample commits' own moment, so the recording describes the same pull request on every install.
    private const string RECORDED_AT = '2026-11-01T00:00:00+00:00';
    private const int COMMAND_SECONDS = 60;
    // Added to the schedule's interval, because a fresh worker sends its first tick one interval after it starts (Symfony's PeriodicalTrigger), and then handles it.
    private const int TICK_MARGIN_SECONDS = 30;
    // Added to the worker's time limit, so a schedule that never fires ends the worker and fails with this class's message, not the process timeout.
    private const int PROCESS_MARGIN_SECONDS = 30;
    private const int GIT_SECONDS = 30;

    /**
     * @param Closure(list<string>, array<string,string>, int): string $console
     */
    public function __construct(
        private Closure $console,
        private ContainerRebuild $container,
        private HardenedGit $hardened = new HardenedGit(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function startOnSamplePullRequest(string $repository, string $configuration): string
    {
        $head = $this->sampleHeadIn($repository);
        $recording = $this->recordPullRequest($configuration, $head);
        $this->watchSampleProject($configuration, $repository);
        $this->rebuildContainer($configuration);
        // Only entries the tick appends count, so a round an earlier start queued for the same head never passes for this tick's round.
        $before = $this->eventIdsIn($this->ledgerLines($configuration));
        $this->tick($configuration, $recording);

        return $this->queuedRound($configuration, $head, $before) ?? throw new RuntimeException(sprintf('The watcher tick on the recording %s queued no round for the sample head %s.', $recording, $head));
    }

    private function sampleHeadIn(string $repository): string
    {
        $call = $this->hardened->call(['rev-parse', '--verify', SampleRepository::BRANCH.'^{commit}'], $repository);

        return trim(new Process($call->command, $call->directory, $call->environment, timeout: self::GIT_SECONDS)->mustRun()->getOutput());
    }

    // Written beside the configuration, so the journey's directory holds and removes everything this start adds.
    private function recordPullRequest(string $configuration, string $head): string
    {
        $recording = dirname($configuration).'/'.self::RECORDING;
        $this->filesystem->dumpFile($recording, json_encode([
            'pulls' => [['number' => self::PULL_REQUEST, 'head' => ['sha' => $head], 'updated_at' => self::RECORDED_AT]],
            'workflow_runs' => [['name' => 'ci', 'event' => 'pull_request', 'head_sha' => $head, 'created_at' => self::RECORDED_AT, 'status' => 'completed', 'conclusion' => 'success']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $recording;
    }

    private function watchSampleProject(string $configuration, string $repository): void
    {
        $written = Yaml::parseFile($configuration);
        $projects = is_array($written) ? $written['projects'] ?? null : null;
        $project = is_array($projects) ? $projects[SampleProject::NAME] ?? null : null;

        if (!is_array($project)) {
            throw new RuntimeException(sprintf('The configuration %s has no sample project to watch; seed it first.', $configuration));
        }

        $projects[SampleProject::NAME] = [...$project, 'watch' => ['repository' => $repository, 'base' => SampleRepository::BASE, 'task' => SampleProject::TASK]];
        $this->filesystem->dumpFile($configuration, Yaml::dump([...$written, 'projects' => $projects], 6, 4));
    }

    // Because the projects are read when the container is built, so the watcher sees the new watch section only in a container built after it.
    private function rebuildContainer(string $configuration): void
    {
        $this->container->rebuild([self::CONFIGURATION_VARIABLE => $configuration], self::COMMAND_SECONDS);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function ledgerLines(string $configuration): array
    {
        $events = ($this->console)(['intakt:ledger:events'], [self::CONFIGURATION_VARIABLE => $configuration], self::COMMAND_SECONDS);
        $lines = [];

        foreach (explode(PHP_EOL, $events) as $line) {
            $fields = explode(' ', $line, 4);

            if (4 === count($fields)) {
                $lines[] = $fields;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $lines
     *
     * @return array<string,true>
     */
    private function eventIdsIn(array $lines): array
    {
        return array_fill_keys(array_column($lines, 1), true);
    }

    private function tick(string $configuration, string $recording): void
    {
        $limit = $this->intervalSeconds() + self::TICK_MARGIN_SECONDS;

        ($this->console)(
            ['messenger:consume', 'scheduler_watcher', '--limit=1', '--time-limit='.$limit],
            [self::CONFIGURATION_VARIABLE => $configuration, self::RECORDING_VARIABLE => $recording],
            $limit + self::PROCESS_MARGIN_SECONDS,
        );
    }

    private function intervalSeconds(): int
    {
        $interval = DateInterval::createFromDateString(WatcherSchedule::INTERVAL);

        return new DateTimeImmutable('@0')->add($interval)->getTimestamp();
    }

    // From the ledger, because the watcher's handler returns nothing a console process could pass on, and the ledger is what a user's round leaves behind.
    /**
     * @param array<string,true> $before
     */
    private function queuedRound(string $configuration, string $head, array $before): ?string
    {
        $rounds = [];

        foreach ($this->ledgerLines($configuration) as $fields) {
            if (!isset($before[$fields[1]]) && LedgerEventKind::RoundQueued->value === $fields[2]) {
                $rounds[] = $this->roundOf($fields[3], $head);
            }
        }

        return array_last(array_filter($rounds, static fn (?string $round): bool => null !== $round));
    }

    private function roundOf(string $payload, string $head): ?string
    {
        try {
            $queued = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new RuntimeException('A round_queued entry of the ledger is not JSON: '.$payload, $invalid->getCode(), previous: $invalid);
        }

        if (!is_array($queued) || !is_string($queued['round'] ?? null) || '' === $queued['round']) {
            throw new RuntimeException('A round_queued entry of the ledger names no round: '.$payload);
        }

        return SampleProject::NAME === ($queued['project'] ?? null) && $head === ($queued['head'] ?? null) ? $queued['round'] : null;
    }
}
