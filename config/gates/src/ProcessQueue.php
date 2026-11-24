<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class ProcessQueue
{
    private const int CANNOT_RUN = 127;

    private const int POLL_MICROSECONDS = 10000;

    public function __construct(private float $seconds, private float $overallSeconds)
    {
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, Closure(int): array{list<string>, array<string, string>}> $jobs
     *
     * @return array<Name, ProcessResult>
     */
    public function run(array $jobs, int $workers, string $directory): array
    {
        // Because the workers take the jobs in the order given, and each job is built for the index of the worker that takes it.
        $order = array_keys($jobs);
        $results = [];
        $running = [];
        $deadline = hrtime(true) + (int) ($this->overallSeconds * 1_000_000_000);
        // A job builder must return without waiting, because the running processes are polled only between builds.
        $start = fn (int $worker, Closure $job): ?array => hrtime(true) < $deadline ? $this->started($this->built($job, $worker), $directory, $deadline) : null;

        while ([] !== $jobs || [] !== $running) {
            $launched = $this->launchedOn($this->idleWorkers($running, $workers), $jobs, $start);
            $jobs = array_diff_key($jobs, array_flip(array_column($launched, 0)));
            $running += $launched;

            // Because a job left waiting at the overall limit would otherwise keep the queue going after its running jobs ended.
            if (hrtime(true) >= $deadline) {
                $results = array_replace($results, $this->neverStarted($jobs));
                $jobs = [];
            }

            $finished = $this->finishedOf($running);
            $running = array_diff_key($running, $finished);
            $results = array_replace($results, array_column($finished, 1, 0));

            usleep(self::POLL_MICROSECONDS);
        }

        return array_combine($order, array_map(static fn (int|string $name): ProcessResult => $results[$name], $order));
    }

    /**
     * @param Closure(int): array{list<string>, array<string, string>} $job
     *
     * @return array{list<string>, array<string, string>}
     */
    private function built(Closure $job, int $worker): array
    {
        return $job($worker);
    }

    /**
     * @param array{list<string>, array<string, string>} $built
     *
     * @return array{Process|ProcessResult, string}
     */
    private function started(array $built, string $directory, int|float $deadline): array
    {
        [$command, $environment] = $built;
        $left = ($deadline - hrtime(true)) / 1_000_000_000;

        // Because building the job may itself have used up the rest of the pass.
        if ($left <= 0) {
            return [$this->notStarted(), ''];
        }

        // Because a job starts a moment after its pass, the time left is rounded up to whole seconds, so the reason of a job timeout equal to the overall limit is the job's own; the process still stops at the time left.
        $ownTimeout = $this->seconds <= ceil($left);
        $process = new Process($command, $directory, [...getenv(), ...$environment], null, min($this->seconds, $left));

        try {
            $process->start();
        } catch (ExceptionInterface) {
            return [new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$command[0]), ''];
        }

        return [$process, $ownTimeout ? 'timed out after '.$this->seconds.' seconds' : $this->overallLimit()];
    }

    /**
     * @param array<int, mixed> $running
     *
     * @return list<int>
     */
    private function idleWorkers(array $running, int $workers): array
    {
        return array_values(array_diff(range(0, $workers - 1), array_keys($running)));
    }

    /**
     * @template Name of array-key
     *
     * @param list<int>                                                                                                     $idle
     * @param array<Name, Closure(int): array{list<string>, array<string, string>}>                                         $jobs
     * @param Closure(int, Closure(int): array{list<string>, array<string, string>}): ?array{Process|ProcessResult, string} $start
     *
     * @return array<int, array{Name, Process|ProcessResult, string}>
     */
    private function launchedOn(array $idle, array $jobs, Closure $start): array
    {
        $launched = [];

        foreach (array_slice(array_keys($jobs), 0, count($idle)) as $index => $name) {
            $started = $start($idle[$index], $jobs[$name]);

            // Because the overall limit may pass while a job is built, and no job may start after it.
            if (null === $started) {
                break;
            }

            $launched[$idle[$index]] = [$name, ...$started];
        }

        return $launched;
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, Closure(int): array{list<string>, array<string, string>}> $jobs
     *
     * @return array<Name, ProcessResult>
     */
    private function neverStarted(array $jobs): array
    {
        return array_fill_keys(array_keys($jobs), $this->notStarted());
    }

    /**
     * @template Name of array-key
     *
     * @param array<int, array{Name, Process|ProcessResult, string}> $running
     *
     * @return array<int, array{Name, ProcessResult}>
     */
    private function finishedOf(array $running): array
    {
        $finished = [];

        foreach ($running as $worker => [$name, $process, $limit]) {
            $result = $process instanceof ProcessResult ? $process : $this->finished($process, $limit);

            if ($result instanceof ProcessResult) {
                $finished[$worker] = [$name, $result];
            }
        }

        return $finished;
    }

    private function notStarted(): ProcessResult
    {
        return new ProcessResult(ProcessRunner::TIMED_OUT, '', $this->overallLimit()."\n");
    }

    private function overallLimit(): string
    {
        return 'timed out: the test pass reached its overall limit of '.$this->overallSeconds.' seconds';
    }

    private function finished(Process $process, string $limit): ?ProcessResult
    {
        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException) {
            return $this->timedOut($process, $limit);
        }

        if ($process->isRunning()) {
            return null;
        }

        return new ProcessResult($process->getExitCode() ?? self::CANNOT_RUN, $process->getOutput(), $process->getErrorOutput());
    }

    // Because a hung test names itself only in what it printed before it stopped answering.
    private function timedOut(Process $process, string $limit): ProcessResult
    {
        $errors = trim($process->getErrorOutput());
        $printed = preg_split('/\R/', '' === $errors ? trim($process->getOutput()) : $errors);
        $lastLine = false === $printed ? '' : (string) array_last($printed);
        $reason = $limit.('' === $lastLine ? '' : ', last output: '.$lastLine);

        return new ProcessResult(ProcessRunner::TIMED_OUT, $process->getOutput(), $reason."\n".$process->getErrorOutput());
    }
}
