<?php

declare(strict_types=1);

namespace Intakt\Upstream\FrankenphpMessengerConsumeSigterm;

use PDO;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The order is forced with marker files: the handler is in flight (started), then SIGTERM, then the handler is released. Plain php is the control.
#[CoversNothing]
final class MessengerConsumeSigtermTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/frankenphp-sigterm-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function runtimes(): iterable
    {
        yield 'plain php' => [[PHP_BINARY]];
        yield 'frankenphp php-cli' => [[self::frankenphp(), 'php-cli']];
    }

    /**
     * @param list<string> $runtime
     */
    #[DataProvider('runtimes')]
    public function testTheConsumerStopsWithExitCodeZeroOnSigtermWhileAMessageIsInFlight(array $runtime): void
    {
        $this->runToEnd($runtime, ['probe:dispatch']);
        $consumer = $this->consume($runtime);
        $this->waitFor('started', $consumer);

        $consumer->signal(SIGTERM);
        touch($this->directory.'/release');
        $this->waitForExit($consumer);

        self::assertSame(0, $consumer->getExitCode(), "output:\n".$consumer->getOutput().$consumer->getErrorOutput());
        self::assertStringNotContainsString('Segmentation fault', $consumer->getErrorOutput());
    }

    /**
     * @param list<string> $runtime
     */
    #[DataProvider('runtimes')]
    public function testTheMessageInFlightIsFinishedOrStillQueuedAfterTheConsumerStops(array $runtime): void
    {
        $this->runToEnd($runtime, ['probe:dispatch']);
        $consumer = $this->consume($runtime);
        $this->waitFor('started', $consumer);

        $consumer->signal(SIGTERM);
        touch($this->directory.'/release');
        $this->waitForExit($consumer);

        $finished = is_file($this->directory.'/done');
        $queued = 1 === $this->queuedMessages();
        self::assertTrue($finished || $queued, 'the message must be handled or still queued, never lost');
    }

    private static function frankenphp(): string
    {
        return new ExecutableFinder()->find('frankenphp') ?? throw new RuntimeException('frankenphp is not installed');
    }

    /**
     * @param list<string> $runtime
     */
    private function consume(array $runtime): Process
    {
        $consumer = $this->process($runtime, ['messenger:consume', 'async', '--limit=1', '--time-limit=60']);
        $consumer->start();

        return $consumer;
    }

    /**
     * @param list<string> $runtime
     * @param list<string> $arguments
     */
    private function runToEnd(array $runtime, array $arguments): void
    {
        $process = $this->process($runtime, $arguments);
        $process->mustRun();
    }

    /**
     * @param list<string> $runtime
     * @param list<string> $arguments
     */
    private function process(array $runtime, array $arguments): Process
    {
        $process = new Process([...$runtime, __DIR__.'/console.php', ...$arguments], __DIR__, ['PROBE_DIR' => $this->directory], null, 90);
        $process->setPty(false);

        return $process;
    }

    private function waitFor(string $marker, Process $consumer): void
    {
        $deadline = hrtime(true) + 30_000_000_000;
        while (!is_file($this->directory.'/'.$marker) && $consumer->isRunning() && hrtime(true) < $deadline) {
            usleep(10_000);
        }

        self::assertFileExists($this->directory.'/'.$marker, "output:\n".$consumer->getOutput().$consumer->getErrorOutput());
    }

    private function waitForExit(Process $consumer): void
    {
        $deadline = hrtime(true) + 30_000_000_000;
        while ($consumer->isRunning() && hrtime(true) < $deadline) {
            usleep(10_000);
        }

        if ($consumer->isRunning()) {
            $consumer->stop(0, SIGKILL);
            self::fail('the consumer did not stop within 30 seconds of SIGTERM');
        }
    }

    private function queuedMessages(): int
    {
        $pdo = new PDO('sqlite:'.$this->directory.'/queue.sqlite');

        return (int) $pdo->query('SELECT COUNT(*) FROM messenger_messages')->fetchColumn();
    }
}
