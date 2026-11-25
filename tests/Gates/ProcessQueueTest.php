<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\ProcessQueue;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\TimeLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ProcessQueue::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(TimeLimit::class)]
final class ProcessQueueTest extends SandboxTestCase
{
    public function testEachJobRunsInTheWorkerWhoseIndexItWasBuiltFor(): void
    {
        $results = new ProcessQueue(30, 60)->run(['first' => $this->printing('worker %d'), 'second' => $this->printing('worker %d')], 2, $this->emptySandbox()->root);

        self::assertSame(['first' => 'worker 0', 'second' => 'worker 1'], array_map(static fn (ProcessResult $result): string => $result->output, $results));
    }

    public function testAWorkerTakesTheNextJobOnlyAfterItsLastOneFinished(): void
    {
        $sandbox = $this->emptySandbox();
        $job = $this->php('$f = fopen("ran", "a"); if (!flock($f, LOCK_EX | LOCK_NB)) { exit(9); } fwrite($f, "x"); usleep(100000);');

        $results = new ProcessQueue(30, 60)->run(['first' => $job, 'second' => $job, 'third' => $job], 1, $sandbox->root);

        self::assertSame([0, 0, 0], array_values(array_map(static fn (ProcessResult $result): int => $result->exitCode, $results)));
        self::assertSame('xxx', file_get_contents($sandbox->root.'/ran'));
    }

    public function testTheWorkersTakeTheJobsInTheOrderTheyAreGiven(): void
    {
        $sandbox = $this->emptySandbox();

        new ProcessQueue(30, 60)->run(['first' => $this->appending('first'), 'second' => $this->appending('second'), 'third' => $this->appending('third')], 1, $sandbox->root);

        self::assertSame('first second third ', file_get_contents($sandbox->root.'/order'));
    }

    public function testTheResultsComeBackInTheOrderOfTheJobsWhicheverFinishesFirst(): void
    {
        $results = new ProcessQueue(30, 60)->run(['slow' => $this->php('usleep(300000);'), 'fast' => $this->php('')], 2, $this->emptySandbox()->root);

        self::assertSame(['slow', 'fast'], array_keys($results));
    }

    public function testAJobThatFailsKeepsItsExitCodeAndItsOutput(): void
    {
        $results = new ProcessQueue(30, 60)->run(['crash' => $this->php('fwrite(STDERR, "the worker crashed\n"); exit(3);')], 1, $this->emptySandbox()->root);

        self::assertSame(3, $results['crash']->exitCode);
        self::assertSame('the worker crashed', $results['crash']->firstErrorLine());
    }

    public function testAtTheOverallLimitTheRunningJobIsStoppedAndTheWaitingOneNeverStarts(): void
    {
        $sandbox = $this->emptySandbox();
        $started = hrtime(true);

        $results = new ProcessQueue(30, 0.3)->run(['hang' => $this->php('echo "waiting for the server\n"; sleep(30);'), 'waiting' => $this->appending('waiting')], 1, $sandbox->root);

        self::assertSame([ProcessRunner::TIMED_OUT, ProcessRunner::TIMED_OUT], [$results['hang']->exitCode, $results['waiting']->exitCode]);
        self::assertSame('timed out: the test pass reached its overall limit of 0.3 seconds, last output: waiting for the server', $results['hang']->firstErrorLine());
        self::assertSame('timed out: the test pass reached its overall limit of 0.3 seconds', $results['waiting']->firstErrorLine());
        self::assertFileDoesNotExist($sandbox->root.'/order');
        self::assertLessThan(20 * 1_000_000_000, hrtime(true) - $started);
    }

    public function testAJobWhoseOwnTimeoutEqualsTheOverallLimitIsStoppedAtItsOwnTimeout(): void
    {
        $results = new ProcessQueue(0.3, 0.3)->run(['sqlite' => $this->php('echo "waiting for the server\n"; sleep(30);')], 2, $this->emptySandbox()->root);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['sqlite']->exitCode);
        self::assertSame('timed out after 0.3 seconds, last output: waiting for the server', $results['sqlite']->firstErrorLine());
    }

    public function testAJobStartedJustBeforeTheOverallLimitIsStoppedAtTheLimitEvenWhenItsOwnTimeoutIsNotLonger(): void
    {
        $late = static function (int $worker): array {
            // Half the limit short of it, because a build that a busy runner lets overrun the limit never starts its job, and the job still runs past the limit.
            usleep(250_000);

            return [['php', '-r', 'usleep(350000); echo "done";'], []];
        };
        $started = hrtime(true);

        $results = new ProcessQueue(0.5, 0.5)->run(['late' => $late], 1, $this->emptySandbox()->root);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        self::assertLessThan(0.9, $elapsed);
        self::assertSame(ProcessRunner::TIMED_OUT, $results['late']->exitCode);
        self::assertSame('timed out after 0.5 seconds', $results['late']->firstErrorLine());
    }

    public function testAJobBuiltPastTheOverallLimitDoesNotStartAndNoJobAfterItIsBuilt(): void
    {
        $sandbox = $this->emptySandbox();
        $lateBuilt = false;
        $slowBuild = function (int $worker): array {
            usleep(400_000);

            return $this->appending('slow')($worker);
        };
        $late = function (int $worker) use (&$lateBuilt): array {
            $lateBuilt = true;

            return $this->appending('late')($worker);
        };

        $results = new ProcessQueue(30, 0.3)->run(['slowBuild' => $slowBuild, 'late' => $late], 2, $sandbox->root);

        self::assertFalse($lateBuilt);
        self::assertSame([ProcessRunner::TIMED_OUT, ProcessRunner::TIMED_OUT], [$results['slowBuild']->exitCode, $results['late']->exitCode]);
        self::assertSame('timed out: the test pass reached its overall limit of 0.3 seconds', $results['late']->firstErrorLine());
        self::assertFileDoesNotExist($sandbox->root.'/order');
    }

    public function testJobsThatEndBeforeTheOverallLimitKeepTheirResults(): void
    {
        $results = new ProcessQueue(30, 30)->run(['first' => $this->php(''), 'second' => $this->php('exit(3);')], 1, $this->emptySandbox()->root);

        self::assertSame([0, 3], [$results['first']->exitCode, $results['second']->exitCode]);
    }

    public function testAJobPastAFractionalTimeoutIsStoppedAndNamesItsLimitAndLastOutput(): void
    {
        $started = hrtime(true);

        $results = new ProcessQueue(0.3, 60)->run(['hang' => $this->php('echo "waiting for the server\n"; sleep(30);')], 1, $this->emptySandbox()->root);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['hang']->exitCode);
        self::assertSame('timed out after 0.3 seconds, last output: waiting for the server', $results['hang']->firstErrorLine());
        self::assertLessThan(20 * 1_000_000_000, hrtime(true) - $started);
    }

    public function testAJobStartedLateInThePassWhoseOwnTimeoutIsLongerThanWhatIsLeftIsNamedByTheOverallLimit(): void
    {
        $late = static function (int $worker): array {
            // Half of the pass is used up before the job starts, so the pass ends long before the job's own limit would.
            usleep(250_000);

            return [['php', '-r', 'sleep(30);'], []];
        };

        $results = new ProcessQueue(0.45, 0.5)->run(['late' => $late], 1, $this->emptySandbox()->root);

        self::assertSame('timed out: the test pass reached its overall limit of 0.5 seconds', $results['late']->firstErrorLine());
    }

    public function testAJobLimitShorterThanTheStartItselfTimesOutAndNamesTheLimit(): void
    {
        $results = new ProcessQueue(1.0E-6, 60)->run(['quick' => $this->php('sleep(2);')], 1, $this->emptySandbox()->root);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['quick']->exitCode);
        self::assertSame('timed out after 1.0E-6 seconds', $results['quick']->firstErrorLine());
    }

    public function testAQueueWithAJobLimitThatIsNotFiniteInNanosecondsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessQueue(PHP_FLOAT_MAX, 60);
    }

    public function testAQueueWithAPassLimitThatIsNotFiniteInNanosecondsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessQueue(30, PHP_FLOAT_MAX);
    }

    public function testAJobThatCannotStartFailsWithoutHoldingItsWorker(): void
    {
        $results = new ProcessQueue(30, 60)->run(['missing' => static fn (int $worker): array => [['/nonexistent/program'], []], 'next' => $this->php('')], 1, $this->emptySandbox()->root);

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
