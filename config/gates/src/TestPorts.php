<?php

declare(strict_types=1);

namespace Intakt\Gates;

use LogicException;

// Because the gate tests run the gate on fixtures inside a test worker, a nested gate must stay inside the range of that worker, or its workers take ports an outer worker's servers hold.
final readonly class TestPorts
{
    // Because Linux hands out port zero from 32768 up, so a port the system picks for a server never lands in a worker's block.
    private const int FIRST_PORT = 20000;
    private const int FIRST_EPHEMERAL_PORT = 32768;
    private const int PORTS_PER_WORKER = 100;
    private const int LAST_PORT = 65535;

    private function __construct(private int $first, private int $perWorker, private int $workers)
    {
    }

    public static function forWorkers(int $workers, ?string $inherited): ?self
    {
        if (null === $inherited) {
            $ports = new self(self::FIRST_PORT, self::PORTS_PER_WORKER, $workers);

            return $ports->lastPort() < self::FIRST_EPHEMERAL_PORT ? $ports : null;
        }

        if (1 !== preg_match('/^(\d{1,5})-(\d{1,5})$/', $inherited, $bounds)) {
            return null;
        }

        [$first, $last] = [(int) $bounds[1], (int) $bounds[2]];
        $perWorker = intdiv($last - $first + 1, $workers);

        return $first >= 1 && $last <= self::LAST_PORT && $perWorker >= 1 ? new self($first, $perWorker, $workers) : null;
    }

    public static function mostWorkersByDefault(): int
    {
        return intdiv(self::FIRST_EPHEMERAL_PORT - self::FIRST_PORT, self::PORTS_PER_WORKER);
    }

    public function block(int $index): string
    {
        if ($index < 0 || $index >= $this->workers) {
            throw new LogicException(sprintf('Worker %d has no block of ports, the gate runs %d workers.', $index, $this->workers));
        }

        $first = $this->first + $index * $this->perWorker;

        return $first.'-'.($first + $this->perWorker - 1);
    }

    public function blocks(): int
    {
        return $this->workers;
    }

    private function lastPort(): int
    {
        return $this->first + $this->workers * $this->perWorker - 1;
    }
}
