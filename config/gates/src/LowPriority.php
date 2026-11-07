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

    // Because only the tests gate's workers gain the processor time the other gates give up, and only while those run beside them; a child never keeps an ask it inherited.
    /**
     * @param list<string> $gates
     *
     * @return array<string, string|false>
     */
    public static function environmentFor(string $gate, array $gates, GateSlots $slots): array
    {
        $tests = new TestsGate()->name();
        $asked = $tests !== $gate && in_array($tests, $gates, true) && $slots->runSeveralAtOnce();

        return [self::VARIABLE => $asked ? self::ASKED : false];
    }

    public static function isAsked(string|false $value): bool
    {
        return self::ASKED === $value;
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
