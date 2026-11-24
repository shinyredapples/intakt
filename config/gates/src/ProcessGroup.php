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
        $this->signal(9);
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
        $this->signal(15);
    }

    public function outlives(float $deadline): bool
    {
        while ($this->isAlive() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->isAlive();
    }

    // Because `setsid` makes the launcher the leader of its group only once it runs, a launcher still in bin/check's own group is reached by its process ID; once it has left that group, the group exists.
    private function signal(int $signal): void
    {
        $notYetLeader = $this->isNotYetLeader();
        posix_kill(-$this->id, $signal);

        if ($notYetLeader) {
            posix_kill($this->id, $signal);
        }
    }

    private function isNotYetLeader(): bool
    {
        return posix_getpgid($this->id) === posix_getpgrp();
    }

    private function isAlive(): bool
    {
        if ($this->isNotYetLeader()) {
            return $this->isLiveLauncher();
        }

        if (!posix_kill(-$this->id, 0)) {
            return false;
        }

        $statuses = glob('/proc/[0-9]*/stat');

        if (false === $statuses || [] === $statuses) {
            return true;
        }

        return [] !== array_filter($statuses, fn (string $status): bool => $this->isLiveMember((string) @file_get_contents($status)));
    }

    private function isLiveLauncher(): bool
    {
        $status = (string) @file_get_contents('/proc/'.$this->id.'/stat');
        $end = strrpos($status, ')');

        return false === $end || self::ZOMBIE !== substr($status, $end + 2, 1);
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
