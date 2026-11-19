<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Process\Process;

// One messenger:consume process of the instance, started as the container entrypoint starts its consumer (the console, the transport, the options), that a journey signals and waits for.
final class ConsumerProcess
{
    private const string ROOT = __DIR__.'/../../..';
    private const float POLL_SECONDS = 0.05;

    private function __construct(private readonly Process $process, private readonly float $startedAt)
    {
    }

    /**
     * @param list<string>         $options
     * @param array<string,string> $environment
     */
    public static function start(string $transport, array $options, array $environment): self
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/console', '--no-interaction', 'messenger:consume', $transport, ...$options], self::ROOT, $environment, null, null);
        $process->start();

        return new self($process, microtime(true));
    }

    public function pid(): int
    {
        return $this->process->getPid() ?? 0;
    }

    public function running(): bool
    {
        return $this->process->isRunning();
    }

    // As `docker stop` signals the container's first process.
    public function terminate(): float
    {
        $this->process->signal(SIGTERM);

        return microtime(true);
    }

    // Seconds from the given moment until the process ended, or null when it still ran after the deadline; it is killed then, so a failed journey leaves no consumer behind.
    public function endedWithin(float $seconds, float $since): ?float
    {
        $deadline = $since + $seconds;

        while ($this->process->isRunning() && microtime(true) < $deadline) {
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        }

        if ($this->process->isRunning()) {
            $this->kill();

            return null;
        }

        return microtime(true) - $since;
    }

    public function hasPrinted(string $text): bool
    {
        return str_contains($this->output(), $text);
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function output(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    public function kill(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0, SIGKILL);
        }
    }

    public function secondsSinceStart(): float
    {
        return microtime(true) - $this->startedAt;
    }
}
