<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;
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

    private const string HOLD_THEN_MOVE = 'read -r released && cd -P -- "$1" && shift && exec "$@"';

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

    // Because a listing too long to hold must be readable in parts, and a reader that stops early ends git with it.
    /**
     * @param list<string> $arguments
     *
     * @return Generator<int, string>
     *
     * @throws RepositoryAttributesRefused
     */
    public function gitInParts(array $arguments, string $directory): Generator
    {
        $call = $this->hardened->call($arguments, $directory);

        return new OutputInParts(new Process($call->command, $call->directory, [...getenv(), ...$call->environment], null, self::OVERALL_TIMEOUT_SECONDS))->read();
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
    public function runWithin(array $command, string $directory, int|float $seconds): ProcessResult
    {
        $refusal = $this->refusal($command[0], $seconds);

        if ($refusal instanceof ProcessResult) {
            return $refusal;
        }

        $release = new InputStream();
        $process = new Process($this->contained($command), $directory, getenv(), $release, $seconds);
        $deadline = (hrtime(true) / 1_000_000_000) + $seconds;
        $group = $this->startHeld($process, $release);

        if (!$group instanceof ProcessGroup) {
            return $this->resultOf($process, $group, $command[0]);
        }

        $forwarding = StopSignalForwarding::through($group->kill(...));
        $failure = $this->waitForAll([$process])[0] ?? null;

        if (null === $failure && $group->outlives($deadline)) {
            $failure = self::TIMED_OUT;
        }

        $group->kill();
        $forwarding->end();

        return $this->resultOf($process, $failure, $command[0]);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    public function runConcurrently(array $commands, string $directory, int|float $seconds = self::OVERALL_TIMEOUT_SECONDS): array
    {
        if ($this->endsAtOnce($seconds)) {
            return array_map(fn (array $command): ProcessResult => $this->timedOut($command[0][0], $seconds), $commands);
        }

        $processes = [];

        foreach ($commands as $name => [$command, $environment]) {
            $processes[$name] = new Process($command, $directory, [...getenv(), ...$environment], null, $seconds);
        }

        $failures = $this->failuresOf($processes);
        $results = [];

        foreach ($processes as $name => $process) {
            $results[$name] = $this->resultOf($process, $failures[$name] ?? null, $commands[$name][0][0]);
        }

        return $results;
    }

    // Because a timed-out gate child must fail with what it printed so far, and nothing it started may overlap the next gate.
    /**
     * @param list<string> $command
     */
    public function runToItsEnd(array $command, string $directory, int|float $seconds = self::OVERALL_TIMEOUT_SECONDS): ProcessResult
    {
        return $this->toItsEnd($this->startedHeld(new Process($this->held($command), $directory, getenv(), null, null), $command[0], $seconds));
    }

    // Because a relative PATH entry resolves in the directory a program starts in, `setsid` and `sh` start where the tools are searched, then the command moves to its own directory.
    public function startToItsEndWithToolsFrom(string $toolsFrom, ChildCommand $child): RunningChild|ProcessResult
    {
        $held = ['setsid', 'sh', '-c', self::HOLD_THEN_MOVE, 'sh', $child->directory, ...$child->command];

        return $this->startedHeld(new Process($held, $toolsFrom, [...getenv(), ...$child->environment], null, null), $child->command[0], self::OVERALL_TIMEOUT_SECONDS);
    }

    // Because Symfony's own timeout sends TERM and KILL at once, before the child can stop the contained run it started in a session of its own.
    public function endOf(RunningChild $child): ?ProcessResult
    {
        $failure = $this->poll($child->process);

        if (self::STILL_RUNNING === $failure && (hrtime(true) / 1_000_000_000) < $child->deadline) {
            return null;
        }

        // Because a timeout after an hour is rare, the other children may wait out this one's stop grace of at most 2 seconds.
        if (self::STILL_RUNNING === $failure) {
            $failure = self::TIMED_OUT;
            $child->group->stop();
            $this->waitForAll([$child->process]);
        }

        $child->group->kill();

        return $this->endedWith($child, $failure);
    }

    private function runCall(GitCall $call): ProcessResult
    {
        return $this->run($call->command, $call->directory, $call->environment);
    }

    // Because in a PID and user namespace git saw other owners and a gate child became PID 1, a gate child gets only a session.
    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function held(array $command): array
    {
        return ['setsid', 'sh', '-c', self::HOLD_UNTIL_RELEASED, 'sh', ...$command];
    }

    private function startedHeld(Process $process, string $program, int|float $seconds): RunningChild|ProcessResult
    {
        $refusal = $this->refusal($program, $seconds);

        if ($refusal instanceof ProcessResult) {
            return $refusal;
        }

        $release = new InputStream();
        $process->setInput($release);
        $deadline = (hrtime(true) / 1_000_000_000) + $seconds;
        $group = $this->startHeld($process, $release);

        if (!$group instanceof ProcessGroup) {
            return $this->resultOf($process, $group, $program);
        }

        return new RunningChild($process, $group, $deadline, $program, $seconds);
    }

    private function toItsEnd(RunningChild|ProcessResult $child): ProcessResult
    {
        if ($child instanceof ProcessResult) {
            return $child;
        }

        $forwarding = StopSignalForwarding::through($child->group->stop(...));
        $result = $this->waitedFor($child);
        $forwarding->end();

        return $result;
    }

    private function waitedFor(RunningChild $child): ProcessResult
    {
        while (true) {
            $result = $this->endOf($child);

            if ($result instanceof ProcessResult) {
                return $result;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function refusal(string $program, int|float $seconds): ?ProcessResult
    {
        $endsAtOnce = $this->endsAtOnce($seconds);

        if (!function_exists('posix_kill')) {
            return new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$program.': the PHP posix extension is missing');
        }

        return $endsAtOnce ? $this->timedOut($program, $seconds) : null;
    }

    // Because a limit that is not finite in nanoseconds would turn the deadline off, TimeLimit refuses it here, before anything starts.
    private function endsAtOnce(int|float $seconds): bool
    {
        return new TimeLimit($seconds)->seconds <= 0;
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function contained(array $command): array
    {
        if (self::PID_NAMESPACE === $this->containment()) {
            return $this->held([$this->unshare, ...self::NAMESPACE_OPTIONS, ...$command]);
        }

        return $this->held($command);
    }

    private function startHeld(Process $process, InputStream $release): ProcessGroup|int
    {
        // Because a process that failed to start or timed out in its start is not running, it has no pid.
        $failures = $this->startAll([$process]);
        $leader = $process->getPid();
        $release->write("\n");
        $release->close();

        if (null === $leader) {
            $process->stop(0);

            return $failures[0] ?? self::CANNOT_RUN;
        }

        return new ProcessGroup($leader);
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

    private function endedWith(RunningChild $child, ?int $failure): ProcessResult
    {
        $process = $child->process;

        if (self::TIMED_OUT === $failure) {
            $timedOut = $this->timedOut($child->program, $child->seconds);

            return new ProcessResult(self::TIMED_OUT, $process->getOutput(), trim($process->getErrorOutput()."\n".$timedOut->errors));
        }

        if (null !== $failure && $process->isStarted() && $process->hasBeenSignaled()) {
            return $this->signaled($process, $child->program);
        }

        return $this->resultOf($process, $failure, $child->program);
    }

    // Because a gate child that crashes on a signal must still fail its gate with what it printed before the signal.
    private function signaled(Process $process, string $program): ProcessResult
    {
        $signal = $process->getTermSignal();
        $ended = $program.' was killed by signal '.$signal;

        return new ProcessResult($process->getExitCode() ?? 128 + $signal, $process->getOutput(), trim($process->getErrorOutput()."\n".$ended));
    }

    private function resultOf(Process $process, ?int $failure, string $program): ProcessResult
    {
        if (self::TIMED_OUT === $failure) {
            return $this->timedOut($program, (float) $process->getTimeout());
        }

        $exitCode = null === $failure ? $process->getExitCode() : null;

        if (null === $exitCode) {
            return new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$program);
        }

        return new ProcessResult($exitCode, $process->getOutput(), $process->getErrorOutput());
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

    private function timedOut(string $program, int|float $seconds): ProcessResult
    {
        return new ProcessResult(self::TIMED_OUT, '', $program.' timed out after '.new TimeLimit($seconds)->label());
    }

    /**
     * @param array<array-key, Process> $processes
     *
     * @return array<array-key, int>
     */
    private function failuresOf(array $processes): array
    {
        $failures = $this->startAll($processes);

        return $failures + $this->waitForAll(array_diff_key($processes, $failures));
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
            // Because Symfony checks the limit as the start ends, a limit shorter than the start itself times out there.
            try {
                $process->start();
            } catch (ProcessTimedOutException) {
                $failures[$name] = self::TIMED_OUT;
            } catch (ExceptionInterface) {
                $failures[$name] = self::CANNOT_RUN;
            }
        }

        return $failures;
    }
}
