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
        return $this->runAll($commands, $directory, $seconds, false);
    }

    // Because a gate child that times out must fail its gate with what it printed so far, not with the timeout alone.
    /**
     * @param list<string> $command
     */
    public function runToItsEnd(array $command, string $directory, int $seconds = self::OVERALL_TIMEOUT_SECONDS): ProcessResult
    {
        return $this->runAll(['command' => [$command, []]], $directory, $seconds, true)['command'];
    }

    private function runCall(GitCall $call): ProcessResult
    {
        return $this->run($call->command, $call->directory, $call->environment);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    private function runAll(array $commands, string $directory, int $seconds, bool $keepPartialOutput): array
    {
        if ($seconds < 1) {
            return array_map(fn (array $command): ProcessResult => $this->timedOut($command[0][0], $seconds), $commands);
        }

        $processes = [];

        foreach ($commands as $name => [$command, $environment]) {
            $processes[$name] = new Process($command, $directory, [...getenv(), ...$environment], null, $seconds);
        }

        $failures = $this->startAll($processes);
        $failures += $this->waitForAll(array_diff_key($processes, $failures));
        $results = [];

        foreach ($processes as $name => $process) {
            $results[$name] = $this->resultOf($process, $failures[$name] ?? null, $commands[$name][0][0], $keepPartialOutput);
        }

        return $results;
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

    private function resultOf(Process $process, ?int $failure, string $program, bool $keepPartialOutput = false): ProcessResult
    {
        if (self::TIMED_OUT === $failure && $keepPartialOutput) {
            $timedOut = $this->timedOut($program, (int) $process->getTimeout());

            return new ProcessResult(self::TIMED_OUT, $process->getOutput(), trim($process->getErrorOutput()."\n".$timedOut->errors));
        }

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
