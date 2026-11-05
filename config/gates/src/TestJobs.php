<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class TestJobs
{
    /**
     * @param Closure(TestWorker): list<string> $command
     * @param array<string, float>              $recorded
     */
    public function __construct(private TestWorkers $workers, private string $root, private Closure $command, private array $recorded)
    {
    }

    /**
     * @param list<TestWorker>             $passes
     * @param array<string, ProcessResult> $listings
     *
     * @return array<string, Closure(int): array{list<string>, array<string, string>}>
     */
    public function queue(array $passes, array $listings): array
    {
        $files = array_map(fn (TestWorker $pass): array => 1 === $this->workers->count ? [] : $this->listedFiles($listings[$pass->run]), $passes);

        return [...$this->wholePasses($passes, $files), ...$this->longestFilesFirst($passes, $files)];
    }

    /**
     * @return list<string>
     */
    private function listedFiles(ProcessResult $listing): array
    {
        preg_match_all('/^ - (.+)$/m', $listing->output, $listed);
        $prefix = rtrim($this->root, '/').'/';

        return array_map(static fn (string $file): string => str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file, $listed[1]);
    }

    /**
     * @param list<TestWorker>   $passes
     * @param list<list<string>> $files
     *
     * @return array<string, Closure(int): array{list<string>, array<string, string>}>
     */
    private function wholePasses(array $passes, array $files): array
    {
        $jobs = [];

        // Because a pass that lists no file must still run whole: with one worker by design, without any test to fail on the empty test suite.
        foreach ($passes as $index => $pass) {
            if ([] === $files[$index]) {
                $jobs[$pass->run] = $this->job(static fn (TestWorker $worker): TestWorker => $worker, $pass);
            }
        }

        return $jobs;
    }

    /**
     * @param list<TestWorker>   $passes
     * @param list<list<string>> $files
     *
     * @return array<string, Closure(int): array{list<string>, array<string, string>}>
     */
    private function longestFilesFirst(array $passes, array $files): array
    {
        $jobs = [];
        $queue = new TestDurations($this->root)->longestFirst(array_values(array_unique(array_merge(...$files))), $this->recorded);

        foreach ($queue as $file) {
            foreach (array_keys(array_filter($files, static fn (array $listed): bool => in_array($file, $listed, true))) as $index) {
                $jobs[$passes[$index]->run.' '.$file] = $this->job(static fn (TestWorker $worker): TestWorker => $worker->taking($file), $passes[$index]);
            }
        }

        return $jobs;
    }

    /**
     * @param Closure(TestWorker): TestWorker $narrowed
     *
     * @return Closure(int): array{list<string>, array<string, string>}
     */
    private function job(Closure $narrowed, TestWorker $pass): Closure
    {
        return function (int $slot) use ($narrowed, $pass): array {
            $worker = $narrowed($this->workers->numbered($pass->run, $pass->configuration, $slot));

            return [($this->command)($worker), $worker->environment()];
        };
    }
}
