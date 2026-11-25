<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Process;

// Because the launcher's PID names its group only once the launcher has run `setsid`, a signal to the group before then reaches no one.
final readonly class ProcessGroup
{
    public const int STOP_GRACE_SECONDS = 2;

    private const int POLL_MICROSECONDS = 10000;

    private const string ZOMBIE = 'Z';

    public function __construct(
        private int $id,
        private Process $launcher,
    ) {
    }

    // Because only an unreaped child's PID is surely still the launcher's, the bare PID is signaled only then, and its zombie then holds the PID, so a session it created in the meantime is still its own.
    public function kill(): void
    {
        if ($this->isOursWithoutItsGroup()) {
            posix_kill($this->id, 9);

            if (posix_getpgid($this->id) !== $this->id) {
                return;
            }
        }

        posix_kill(-$this->id, 9);
    }

    // Because only the gate child can reach the session of its contained run, it is asked to stop first and killed when it does not.
    public function stop(): void
    {
        $deadline = (hrtime(true) / 1_000_000_000) + self::STOP_GRACE_SECONDS;
        $this->waitForItsGroup($deadline);
        $this->askToStop();
        $this->outlives($deadline);
        $this->kill();
    }

    // Because a launcher that has no group yet runs nothing it could stop, it is not asked; `stop()` waits for its group or kills it.
    public function askToStop(): void
    {
        if (!$this->isOursWithoutItsGroup()) {
            posix_kill(-$this->id, 15);
        }
    }

    public function outlives(float $deadline): bool
    {
        while ($this->isAlive() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->isAlive();
    }

    // Because asking the group before the launcher leads it would miss what the launcher starts right after, the stop waits, within its grace, until the launcher leads its group or has ended.
    private function waitForItsGroup(float $deadline): void
    {
        while ($this->isOursWithoutItsGroup() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }
    }

    // Because Symfony reaps the launcher inside `isRunning()`, true there means its PID cannot have passed to another process yet.
    private function isOursWithoutItsGroup(): bool
    {
        return $this->launcher->isRunning() && posix_getpgid($this->id) !== $this->id;
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
