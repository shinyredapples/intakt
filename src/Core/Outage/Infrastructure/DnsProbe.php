<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpProcess;

// In a child process the timeout stops, because PHP's lookup functions take no timeout and the system resolver's retries can last far longer than a few seconds.
final readonly class DnsProbe implements Probe
{
    public const string LOOKUP = '<?php exit(false === gethostbynamel((string) getenv("INTAKT_PROBE_HOST")) ? 1 : 0);';

    public function __construct(
        public string $host,
        private float $timeout,
        private string $lookup = self::LOOKUP,
    ) {
    }

    public function answers(): bool
    {
        $lookup = new PhpProcess($this->lookup, null, ['INTAKT_PROBE_HOST' => $this->host])->setTimeout($this->timeout);

        try {
            return 0 === $lookup->run();
        } catch (ProcessTimedOutException) {
            return false;
        }
    }
}
