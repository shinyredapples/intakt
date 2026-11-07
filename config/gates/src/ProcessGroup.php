<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ProcessGroup
{
    public const int STOP_GRACE_SECONDS = 2;

    private const int POLL_MICROSECONDS = 10000;

    private const string ZOMBIE = 'Z';

    public function __construct(
        private int $id,
    ) {
    }

    public function kill(): void
    {
        posix_kill(-$this->id, 9);
    }

    // Because only the gate child can reach the session of its contained run, it is asked to stop first and killed when it does not.
    public function stop(): void
    {
        $this->askToStop();
        $this->outlives((hrtime(true) / 1_000_000_000) + self::STOP_GRACE_SECONDS);
        $this->kill();
    }

    public function askToStop(): void
    {
        posix_kill(-$this->id, 15);
    }

    public function outlives(float $deadline): bool
    {
        while ($this->isAlive() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->isAlive();
    }

    private function isAlive(): bool
    {
        if (!posix_kill(-$this->id, 0)) {
            return false;
        }

        $statuses = glob('/proc/[0-9]*/stat');

        if (false === $statuses || [] === $statuses) {
            return true;
        }

        return [] !== array_filter($statuses, fn (string $status): bool => $this->isLiveMember((string) @file_get_contents($status)));
    }

    private function isLiveMember(string $status): bool
    {
        $end = strrpos($status, ')');

        if (false === $end) {
            return false;
        }

        $fields = explode(' ', substr($status, $end + 2));

        return self::ZOMBIE !== $fields[0] && (string) $this->id === ($fields[2] ?? '');
    }
}
