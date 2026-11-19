<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Process\Process;

// Started with plain `php bin/console`, as docker/entrypoint.sh starts its consumer, so the journey signals the process the container would signal.
final readonly class ConsumerProcess
{
    private const string ROOT = __DIR__.'/../../..';
    private const float POLL_SECONDS = 0.05;

    private function __construct(private Process $process)
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

        return new self($process);
    }

    public function pid(): int
    {
        return $this->process->getPid() ?? 0;
    }

    public function running(): bool
    {
        return $this->process->isRunning();
    }

    // SIGTERM, because that is what `docker stop` sends the container's first process; returns the moment it was sent.
    public function terminate(): float
    {
        $this->process->signal(SIGTERM);

        return MonotonicSeconds::now();
    }

    // Killed once the deadline passed, so a failed journey leaves no consumer behind; null then, else the seconds from the given moment until it ended.
    public function endedWithin(float $seconds, float $since): ?float
    {
        $deadline = $since + $seconds;

        while ($this->process->isRunning() && MonotonicSeconds::now() < $deadline) {
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        }

        if ($this->process->isRunning()) {
            $this->kill();

            return null;
        }

        return MonotonicSeconds::now() - $since;
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
}
