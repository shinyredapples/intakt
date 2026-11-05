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

    public function __construct(private int $seconds)
    {
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, Closure(int): array{list<string>, array<string, string>}> $jobs in the order the workers take them, each built for the index of the worker that takes it
     *
     * @return array<Name, ProcessResult>
     */
    public function run(array $jobs, int $workers, string $directory): array
    {
        $order = array_keys($jobs);
        $results = [];
        $running = [];

        while ([] !== $jobs || [] !== $running) {
            foreach ($this->idleWorkers($running, $workers) as $worker) {
                $name = array_key_first($jobs);

                if (null === $name) {
                    break;
                }

                [$command, $environment] = $jobs[$name]($worker);
                unset($jobs[$name]);
                $process = $this->started($command, $directory, $environment);

                if ($process instanceof ProcessResult) {
                    $results[$name] = $process;

                    continue;
                }

                $running[$worker] = [$name, $process];
            }

            foreach ($running as $worker => [$name, $process]) {
                $result = $this->finished($process);

                if ($result instanceof ProcessResult) {
                    $results[$name] = $result;
                    unset($running[$worker]);
                }
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return array_combine($order, array_map(static fn (int|string $name): ProcessResult => $results[$name], $order));
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
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function started(array $command, string $directory, array $environment): Process|ProcessResult
    {
        $process = new Process($command, $directory, [...getenv(), ...$environment], null, $this->seconds);

        try {
            $process->start();
        } catch (ExceptionInterface) {
            return new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$command[0]);
        }

        return $process;
    }

    private function finished(Process $process): ?ProcessResult
    {
        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException) {
            return $this->timedOut($process);
        }

        if ($process->isRunning()) {
            return null;
        }

        return new ProcessResult($process->getExitCode() ?? self::CANNOT_RUN, $process->getOutput(), $process->getErrorOutput());
    }

    // Because a hung test names itself only in what it printed before it stopped answering.
    private function timedOut(Process $process): ProcessResult
    {
        $errors = trim($process->getErrorOutput());
        $printed = preg_split('/\R/', '' === $errors ? trim($process->getOutput()) : $errors);
        $lastLine = false === $printed ? '' : (string) array_last($printed);
        $reason = 'timed out after '.$this->seconds.' seconds'.('' === $lastLine ? '' : ', last output: '.$lastLine);

        return new ProcessResult(ProcessRunner::TIMED_OUT, $process->getOutput(), $reason."\n".$process->getErrorOutput());
    }
}
