<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Gates\TestWorker;
use LogicException;

// Because a port the system picks is free only until a server binds it, another worker could take it in between; ports from this worker's own range cannot be.
final class WorkerPorts
{
    private ?int $next = null;
    private int $first = 0;
    private int $last = 0;

    public function __construct(?string $range)
    {
        if (null === $range) {
            return;
        }

        if (1 !== preg_match('/^(\d{1,5})-(\d{1,5})$/', $range, $bounds) || 0 === (int) $bounds[1] || (int) $bounds[1] > (int) $bounds[2]) {
            throw new LogicException(sprintf('%s must name a range of ports such as 20000-20099, not "%s".', TestWorker::PORTS_VARIABLE, $range));
        }

        $this->first = (int) $bounds[1];
        $this->next = $this->first;
        $this->last = (int) $bounds[2];
    }

    public static function ofThisWorker(): self
    {
        $range = getenv(TestWorker::PORTS_VARIABLE);

        return new self(is_string($range) && '' !== $range ? $range : null);
    }

    public function next(): int
    {
        if (null === $this->next) {
            return $this->portTheSystemPicks();
        }

        for (; $this->next <= $this->last; ++$this->next) {
            if ($this->isFree($this->next)) {
                return $this->next++;
            }
        }

        throw new LogicException(sprintf('No free port is left in %d-%d, the range of this test worker.', $this->first, $this->last));
    }

    // Because outside the tests gate there is no other worker, so a port the system picks is as good as any.
    private function portTheSystemPicks(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if (false === $socket) {
            throw new LogicException('The system has no free port for a test server.');
        }

        $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    private function isFree(int $port): bool
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:'.$port);

        if (false === $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
