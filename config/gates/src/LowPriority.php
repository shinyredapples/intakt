<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LowPriority
{
    public const string VARIABLE = 'INTAKT_GATE_LOW_PRIORITY';

    public const int NICENESS = 19;

    private const string ASKED = '1';

    private const string AUTOGROUP = '/proc/self/autogroup';

    public function __construct(
        private string $autogroup = self::AUTOGROUP,
    ) {
    }

    // Because only the tests gate's workers gain the processor time the other gates give up, and only the gates that also run the change's code run beside them; a child never keeps an ask it inherited.
    /**
     * @param list<string> $runningTheChangesCode
     *
     * @return array<string, string|false>
     */
    public static function environmentFor(string $gate, array $runningTheChangesCode, GateSlots $slots): array
    {
        $tests = new TestsGate()->name();
        $asked = $tests !== $gate && in_array($gate, $runningTheChangesCode, true) && in_array($tests, $runningTheChangesCode, true) && $slots->runSeveralAtOnce();

        return [self::VARIABLE => $asked ? self::ASKED : false];
    }

    /**
     * @param array<string, string> $environment
     */
    public static function isAskedIn(array $environment): bool
    {
        return self::ASKED === ($environment[self::VARIABLE] ?? null);
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
