<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\PhpExecutableFinder;
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
        $finder = new PhpExecutableFinder();
        $php = $finder->find(false);

        // Without a PHP executable the lookup cannot run, and PhpProcess cannot even be built.
        if (false === $php) {
            return false;
        }

        // The process is built inside the attempt, because without `proc_open` its constructor already throws.
        try {
            return 0 === new PhpProcess($this->lookup, null, ['INTAKT_PROBE_HOST' => $this->host], php: [$php, ...$finder->findArguments()])
                ->setTimeout($this->timeout)
                ->run();
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
