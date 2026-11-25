<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessGroup::class)]
final class GateChildStopFinalSignalsTest extends TestCase
{
    // Because a launcher that does not end within the grace after its kill cannot be produced on demand, a PHP process of its own replaces the clock and the process calls in the gates' namespace: launcher 10001 stays held before its session and never ends, 10002 leads its group.
    private const string HELD = <<<'PHP'
        namespace Intakt\Gates {
            function hrtime(bool $numeric): int
            {
                return $GLOBALS['tick'];
            }

            function usleep(int $delay): void
            {
                $GLOBALS['tick'] += $delay * 1000;
            }

            function file_get_contents(string $path): string
            {
                return '(launcher) '.implode(' ', array_fill(0, 20, '1'));
            }

            function pcntl_waitid(int $type, int $pid, &$info, int $options): bool
            {
                $info = ['pid' => 0];

                return true;
            }

            function posix_getpgid(int $pid): int
            {
                return 10002 === $pid ? $pid : 9999;
            }

            function posix_kill(int $pid, int $signal): bool
            {
                if (0 !== $signal) {
                    $GLOBALS['sent'][] = [$pid, $signal, $GLOBALS['tick'] / 1_000_000_000];
                }

                return 0 !== $signal;
            }
        }

        namespace {
            require $argv[1];
            $GLOBALS['tick'] = 0;
            $GLOBALS['sent'] = [];
            Intakt\Gates\ProcessGroup::stopTogether([
                Intakt\Gates\ProcessGroup::ofLauncher(10001),
                Intakt\Gates\ProcessGroup::ofLauncher(10002),
            ]);
            echo json_encode(array_values(array_filter($GLOBALS['sent'], static fn (array $sent): bool => 9 === $sent[1])));
        }
        PHP;

    public function testALauncherThatHasNotEndedAfterItsKillDoesNotDelayTheKillOfTheGroupsBehindIt(): void
    {
        $process = new Process([PHP_BINARY, '-r', self::HELD, dirname(__DIR__, 2).'/vendor/autoload.php']);
        $process->mustRun();

        self::assertSame([[10001, 9, 2], [-10002, 9, 2]], json_decode($process->getOutput(), true), $process->getOutput());
    }
}
