<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\ProcessQueue;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ProcessQueue::class)]
#[UsesClass(ProcessResult::class)]
final class ProcessQueueTest extends SandboxTestCase
{
    public function testEachJobRunsInTheWorkerWhoseIndexItWasBuiltFor(): void
    {
        $results = new ProcessQueue(30)->run(['first' => $this->printing('worker %d'), 'second' => $this->printing('worker %d')], 2, $this->emptySandbox()->root);

        self::assertSame(['first' => 'worker 0', 'second' => 'worker 1'], array_map(static fn (ProcessResult $result): string => $result->output, $results));
    }

    public function testAWorkerTakesTheNextJobOnlyAfterItsLastOneFinished(): void
    {
        $sandbox = $this->emptySandbox();
        $job = $this->php('$f = fopen("ran", "a"); if (!flock($f, LOCK_EX | LOCK_NB)) { exit(9); } fwrite($f, "x"); usleep(100000);');

        $results = new ProcessQueue(30)->run(['first' => $job, 'second' => $job, 'third' => $job], 1, $sandbox->root);

        self::assertSame([0, 0, 0], array_values(array_map(static fn (ProcessResult $result): int => $result->exitCode, $results)));
        self::assertSame('xxx', file_get_contents($sandbox->root.'/ran'));
    }

    public function testTheWorkersTakeTheJobsInTheOrderTheyAreGiven(): void
    {
        $sandbox = $this->emptySandbox();

        new ProcessQueue(30)->run(['first' => $this->appending('first'), 'second' => $this->appending('second'), 'third' => $this->appending('third')], 1, $sandbox->root);

        self::assertSame('first second third ', file_get_contents($sandbox->root.'/order'));
    }

    public function testTheResultsComeBackInTheOrderOfTheJobsWhicheverFinishesFirst(): void
    {
        $results = new ProcessQueue(30)->run(['slow' => $this->php('usleep(300000);'), 'fast' => $this->php('')], 2, $this->emptySandbox()->root);

        self::assertSame(['slow', 'fast'], array_keys($results));
    }

    public function testAJobThatFailsKeepsItsExitCodeAndItsOutput(): void
    {
        $results = new ProcessQueue(30)->run(['crash' => $this->php('fwrite(STDERR, "the worker crashed\n"); exit(3);')], 1, $this->emptySandbox()->root);

        self::assertSame(3, $results['crash']->exitCode);
        self::assertSame('the worker crashed', $results['crash']->firstErrorLine());
    }

    public function testAJobPastTheTimeoutIsStoppedAndNamesItsLastOutput(): void
    {
        $started = hrtime(true);

        $results = new ProcessQueue(1)->run(['hang' => $this->php('echo "waiting for the server\n"; sleep(30);')], 1, $this->emptySandbox()->root);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['hang']->exitCode);
        self::assertSame('timed out after 1 seconds, last output: waiting for the server', $results['hang']->firstErrorLine());
        self::assertLessThan(20 * 1_000_000_000, hrtime(true) - $started);
    }

    public function testAJobThatCannotStartFailsWithoutHoldingItsWorker(): void
    {
        $results = new ProcessQueue(30)->run(['missing' => static fn (int $worker): array => [['/nonexistent/program'], []], 'next' => $this->php('')], 1, $this->emptySandbox()->root);

        self::assertNotSame(0, $results['missing']->exitCode);
        self::assertSame(0, $results['next']->exitCode);
    }

    /**
     * @return Closure(int): array{list<string>, array<string, string>}
     */
    private function php(string $code): Closure
    {
        return static fn (int $worker): array => [['php', '-r', $code], []];
    }

    /**
     * @return Closure(int): array{list<string>, array<string, string>}
     */
    private function printing(string $format): Closure
    {
        return static fn (int $worker): array => [['php', '-r', 'echo getenv("WORKER");'], ['WORKER' => sprintf($format, $worker)]];
    }

    /**
     * @return Closure(int): array{list<string>, array<string, string>}
     */
    private function appending(string $name): Closure
    {
        return $this->php('file_put_contents("order", "'.$name.' ", FILE_APPEND);');
    }
}
