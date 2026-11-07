<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LowPriority
{
    public const int NICENESS = 19;

    private const string AUTOGROUP = '/proc/self/autogroup';

    public function __construct(
        private string $autogroup = self::AUTOGROUP,
    ) {
    }

    public static function isFor(string $gate): bool
    {
        return new TestsGate()->name() !== $gate;
    }

    // Because the gates beside the tests gate would otherwise take the processors its workers wait for, they get only what the workers leave idle.
    public function take(): void
    {
        @proc_nice(self::NICENESS);
        $this->takeForTheSession();
    }

    // Because each gate child leads a session of its own, and where the kernel shares the processors between sessions (autogroups) a process's own niceness counts only inside its session.
    public function takeForTheSession(): bool
    {
        return is_writable($this->autogroup) && false !== @file_put_contents($this->autogroup, (string) self::NICENESS);
    }
}
