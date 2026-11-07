<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateSlots
{
    public const string VARIABLE = 'INTAKT_GATE_SLOTS';

    public const int MOST = 999;

    private function __construct(
        private int $count,
        private int $testsShare,
    ) {
    }

    public static function chosen(?string $override, int $processors, int $testWorkers): ?self
    {
        if (null !== $override && 1 !== preg_match('/^[1-9]\d{0,2}$/', $override)) {
            return null;
        }

        $count = null === $override ? $processors : (int) $override;

        // Because the test workers use the same processors as the other gates, yet one other gate still runs beside them.
        return new self($count, max(1, min($testWorkers, $count - 1)));
    }

    // Because the gates share the memory of their cgroups (`memory.max`, `memory.high`), each gate and each test worker gets the most one gate asks for, PHPStan's limit, and the test workers hold theirs first; `max` or anything unreadable sets no cap.
    /**
     * @param list<string> $memoryLimits
     */
    public static function byDefault(int $processors, array $memoryLimits, int $testWorkers): int
    {
        $limits = array_map(intval(...), array_filter(array_map(trim(...), $memoryLimits), static fn (string $limit): bool => 1 === preg_match('/^[1-9]\d*$/', $limit)));

        if ([] === $limits) {
            return $processors;
        }

        $perGate = StaticAnalysisGate::MEMORY_LIMIT_GIB * 1024 ** 3;

        return max(1, min($processors, intdiv(min($limits), $perGate) - $testWorkers + 1));
    }

    /**
     * @param list<string> $gates
     *
     * @return list<string>
     */
    public function launchOrder(array $gates): array
    {
        $tests = [new TestsGate()->name()];

        // Because the tests gate takes longest by far, it starts first and never waits for a slot.
        return [...array_values(array_intersect($gates, $tests)), ...array_values(array_diff($gates, $tests))];
    }

    public function runSeveralAtOnce(): bool
    {
        return $this->count > 1;
    }

    /**
     * @param list<string> $running
     */
    public function allow(array $running, string $gate): bool
    {
        return array_sum(array_map($this->shareOf(...), $running)) + $this->shareOf($gate) <= $this->count;
    }

    private function shareOf(string $gate): int
    {
        return new TestsGate()->name() === $gate ? $this->testsShare : 1;
    }
}
