<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ProcessRunner
{
    public const int TIMED_OUT = 124;

    public const string PID_NAMESPACE = 'PID namespace';

    public const string PROCESS_GROUP = 'process group';

    private const int CANNOT_RUN = 127;

    private const int OVERALL_TIMEOUT_SECONDS = 3600;

    private const int STILL_RUNNING = -1;

    private const int POLL_MICROSECONDS = 10000;

    private const array NAMESPACE_OPTIONS = ['--user', '--map-root-user', '--pid', '--fork', '--kill-child', '--mount-proc'];

    private const int PROBE_TIMEOUT_SECONDS = 10;

    private const string HOLD_UNTIL_RELEASED = 'read -r released && exec "$@"';

    private const string ZOMBIE = 'Z';

    private ?string $containment = null;

    public function __construct(
        private readonly string $unshare = 'unshare',
        private readonly HardenedGitCall $hardened = new HardenedGitCall(),
        private readonly int $slotSeconds = self::OVERALL_TIMEOUT_SECONDS,
    ) {
    }

    public function containment(): string
    {
        return $this->containment ??= $this->namespacesAreAvailable() ? self::PID_NAMESPACE : self::PROCESS_GROUP;
    }

    /**
     * @param list<string> $arguments
     */
    public function git(array $arguments, string $directory): ProcessResult
    {
        return $this->runCall($this->hardened->call($arguments, $directory));
    }

    /**
     * @param list<string> $arguments
     */
    public function gitWithPathspecMagic(array $arguments, string $directory): ProcessResult
    {
        return $this->runCall($this->hardened->callWithPathspecMagic($arguments, $directory));
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function run(array $command, string $directory, array $environment = []): ProcessResult
    {
        return $this->runConcurrently(['command' => [$command, $environment]], $directory)['command'];
    }

    /**
     * @param list<string> $command
     */
    public function runWithin(array $command, string $directory, int $seconds): ProcessResult
    {
        $refusal = $this->refusal($command[0], $seconds);

        if ($refusal instanceof ProcessResult) {
            return $refusal;
        }

        $release = new InputStream();
        $process = new Process($this->contained($command), $directory, getenv(), $release, $seconds);
        $deadline = (hrtime(true) / 1_000_000_000) + $seconds;
        $group = $this->startHeld($process, $release);

        if (null === $group) {
            return $this->resultOf($process, self::CANNOT_RUN, $command[0]);
        }

        $failure = $this->waitForAll([$process])[0] ?? null;

        if (null === $failure && $this->groupOutlives($group, $deadline)) {
            $failure = self::TIMED_OUT;
        }

        posix_kill(-$group, 9);

        return $this->resultOf($process, $failure, $command[0]);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    public function runConcurrently(array $commands, string $directory, int $seconds = self::OVERALL_TIMEOUT_SECONDS): array
    {
        if ($seconds < 1) {
            return array_map(fn (array $command): ProcessResult => $this->timedOut($command[0][0], $seconds), $commands);
        }

        $processes = $this->processes($commands, $directory, $seconds);
        $failures = $this->startAll($processes);
        $failures += $this->waitForAll(array_diff_key($processes, $failures));

        return $this->resultsOf($processes, $failures, $commands);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    public function runAtMost(ProcessSlots $slots, array $commands, string $directory): array
    {
        $processes = $this->processes($this->leadingOwnGroups($commands), $directory, $this->slotSeconds);
        $failures = $this->runInSlots($slots, $processes);
        $results = $this->resultsOf($processes, $failures, $commands);

        // Because a gate that timed out is reported with what its child printed, which tells where it got stuck.
        foreach ($results as $name => $result) {
            if (self::TIMED_OUT === ($failures[$name] ?? null)) {
                $results[$name] = new ProcessResult(self::TIMED_OUT, $processes[$name]->getOutput(), $processes[$name]->getErrorOutput().$result->errors);
            }
        }

        return $results;
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, array{list<string>, array<string, string|false>}>
     */
    private function leadingOwnGroups(array $commands): array
    {
        // Because stopping a timed-out child alone would leave the tool and the workers it started running in its slot: as the leader of its own process group, the child is killed together with them.
        return array_map(static fn (array $command): array => [['setsid', ...$command[0]], $command[1]], $commands);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, Process>
     */
    private function processes(array $commands, string $directory, int $seconds): array
    {
        $processes = [];

        foreach ($commands as $name => [$command, $environment]) {
            $processes[$name] = new Process($command, $directory, [...getenv(), ...$environment], null, $seconds);
        }

        return $processes;
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, Process>                                          $processes
     * @param array<array-key, int>                                         $failures
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    private function resultsOf(array $processes, array $failures, array $commands): array
    {
        $results = [];

        foreach ($processes as $name => $process) {
            $results[$name] = $this->resultOf($process, $failures[$name] ?? null, $commands[$name][0][0]);
        }

        return $results;
    }

    /**
     * @param array<array-key, Process> $waiting
     *
     * @return array<array-key, int>
     */
    private function runInSlots(ProcessSlots $slots, array $waiting): array
    {
        $running = [];
        $groups = [];
        $failures = [];

        while ([] !== $waiting || [] !== $running) {
            $starting = $slots->nextToStart($waiting, $running);
            $waiting = array_diff_key($waiting, $starting);
            $failures += $this->startAll($starting);
            $started = array_diff_key($starting, $failures);
            $running += $started;
            $groups += $this->groupsOf($started);

            $finished = $this->pollAll($running);
            $running = array_diff_key($running, $finished);
            $stopped = array_filter($finished, static fn (?int $failure): bool => null !== $failure);
            $failures += $stopped;
            $this->killGroups(array_intersect_key($groups, $stopped));

            usleep(self::POLL_MICROSECONDS);
        }

        return $failures;
    }

    /**
     * @param array<array-key, Process> $started
     *
     * @return array<array-key, int>
     */
    private function groupsOf(array $started): array
    {
        // Because Symfony Process forgets the pid once the child ended, the group is recorded right after the start.
        return array_filter(array_map(static fn (Process $process): ?int => $process->getPid(), $started), is_int(...));
    }

    /**
     * @param array<array-key, int> $groups
     */
    private function killGroups(array $groups): void
    {
        // Because the slot of a child the runner stopped is free only once the tool it started has ended with it.
        foreach ($groups as $group) {
            posix_kill(-$group, 9);
        }
    }

    private function runCall(GitCall $call): ProcessResult
    {
        return $this->run($call->command, $call->directory, $call->environment);
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function contained(array $command): array
    {
        $held = ['setsid', 'sh', '-c', self::HOLD_UNTIL_RELEASED, 'sh'];

        if (self::PID_NAMESPACE === $this->containment()) {
            return [...$held, $this->unshare, ...self::NAMESPACE_OPTIONS, ...$command];
        }

        return [...$held, ...$command];
    }

    private function refusal(string $program, int $seconds): ?ProcessResult
    {
        if (!function_exists('posix_kill')) {
            return new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$program.': the PHP posix extension is missing');
        }

        return $seconds < 1 ? $this->timedOut($program, $seconds) : null;
    }

    private function startHeld(Process $process, InputStream $release): ?int
    {
        $group = [] === $this->startAll([$process]) ? $process->getPid() : null;
        $release->write("\n");
        $release->close();

        if (null === $group) {
            $process->stop(0);
        }

        return $group;
    }

    private function namespacesAreAvailable(): bool
    {
        $probe = new Process([$this->unshare, ...self::NAMESPACE_OPTIONS, 'true'], null, null, null, self::PROBE_TIMEOUT_SECONDS);

        try {
            return 0 === $probe->run();
        } catch (ExceptionInterface) {
            return false;
        }
    }

    private function groupOutlives(int $group, float $deadline): bool
    {
        while ($this->groupIsAlive($group) && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->groupIsAlive($group);
    }

    private function groupIsAlive(int $group): bool
    {
        if (!posix_kill(-$group, 0)) {
            return false;
        }

        $statuses = glob('/proc/[0-9]*/stat');

        if (false === $statuses || [] === $statuses) {
            return true;
        }

        return [] !== array_filter($statuses, fn (string $status): bool => $this->isLiveMember($group, (string) @file_get_contents($status)));
    }

    private function isLiveMember(int $group, string $status): bool
    {
        $end = strrpos($status, ')');

        if (false === $end) {
            return false;
        }

        $fields = explode(' ', substr($status, $end + 2));

        return self::ZOMBIE !== $fields[0] && (string) $group === ($fields[2] ?? '');
    }

    /**
     * @param array<array-key, Process> $processes
     *
     * @return array<array-key, int>
     */
    private function startAll(array $processes): array
    {
        $failures = [];

        foreach ($processes as $name => $process) {
            try {
                $process->start();
            } catch (ExceptionInterface) {
                $failures[$name] = self::CANNOT_RUN;
            }
        }

        return $failures;
    }

    /**
     * @param array<array-key, Process> $processes
     *
     * @return array<array-key, int>
     */
    private function waitForAll(array $processes): array
    {
        $failures = [];

        while ([] !== $processes) {
            $finished = $this->pollAll($processes);
            $processes = array_diff_key($processes, $finished);
            $failures += array_filter($finished, static fn (?int $failure): bool => null !== $failure);

            usleep(self::POLL_MICROSECONDS);
        }

        return $failures;
    }

    /**
     * @param array<array-key, Process> $processes
     *
     * @return array<array-key, int|null>
     */
    private function pollAll(array $processes): array
    {
        $finished = [];

        foreach ($processes as $name => $process) {
            $failure = $this->poll($process);

            if (self::STILL_RUNNING !== $failure) {
                $finished[$name] = $failure;
            }
        }

        return $finished;
    }

    private function poll(Process $process): ?int
    {
        try {
            $process->checkTimeout();

            if ($process->isRunning()) {
                return self::STILL_RUNNING;
            }

            $process->wait();
        } catch (ProcessTimedOutException) {
            return self::TIMED_OUT;
        } catch (ExceptionInterface) {
            $process->stop(0);

            return self::CANNOT_RUN;
        }

        return null;
    }

    private function resultOf(Process $process, ?int $failure, string $program): ProcessResult
    {
        if (self::TIMED_OUT === $failure) {
            return $this->timedOut($program, (int) $process->getTimeout());
        }

        $exitCode = null === $failure ? $process->getExitCode() : null;

        if (null === $exitCode) {
            return new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$program);
        }

        return new ProcessResult($exitCode, $process->getOutput(), $process->getErrorOutput());
    }

    private function timedOut(string $program, int $seconds): ProcessResult
    {
        return new ProcessResult(self::TIMED_OUT, '', $program.' timed out after '.$seconds.' seconds');
    }
}
