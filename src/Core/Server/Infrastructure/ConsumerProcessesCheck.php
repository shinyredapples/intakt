<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// The workers cannot report that they are gone, so the doctor looks for the consumers docker/entrypoint.sh starts in the process list, which only reads (decisions D2 and D3 of #3827).
final readonly class ConsumerProcessesCheck
{
    private const string CONSUME = 'messenger:consume';
    /** @var array<string, string> the receiver each consumer kind reads, and how its absence reads */
    private const array KINDS = [
        'async' => 'no async worker is running, so no review or build moves',
        'scheduler_watcher' => 'the scheduler worker is not running, so pull requests are not watched',
    ];
    private const string MISSING_FIX = 'restart the container with docker compose restart intakt; outside the container, start them with messenger:consume async and messenger:consume scheduler_watcher scheduler_retention';
    private const string CANNOT_RUN_FIX = 'run the doctor inside the container: docker compose exec intakt php bin/console intakt:doctor';

    public function __construct(
        private string $processRoot = '/proc',
    ) {
    }

    public function run(): CheckVerdict
    {
        $commandLines = $this->commandLines();
        if (null === $commandLines) {
            return CheckVerdict::failed('cannot run (the process list under '.$this->processRoot.' cannot be read)', self::CANNOT_RUN_FIX);
        }
        $missing = array_keys(array_filter(self::KINDS, fn (string $problem, string $receiver): bool => !$this->runs($receiver, $commandLines), ARRAY_FILTER_USE_BOTH));

        return [] === $missing
            ? CheckVerdict::passed()
            : CheckVerdict::failed(implode('; ', array_map(static fn (string $receiver): string => self::KINDS[$receiver], $missing)), self::MISSING_FIX);
    }

    /**
     * @return list<list<string>>|null
     */
    private function commandLines(): ?array
    {
        $files = is_dir($this->processRoot) && is_readable($this->processRoot) ? glob($this->processRoot.'/[0-9]*/cmdline') : false;
        if (false === $files || [] === $files) {
            return null;
        }
        $lines = [];
        foreach ($files as $file) {
            // Quiet, because a process may end between the listing and the read.
            $line = @file_get_contents($file);
            if (is_string($line) && '' !== $line) {
                $lines[] = explode("\0", rtrim($line, "\0"));
            }
        }

        return $lines;
    }

    /**
     * @param list<list<string>> $commandLines
     */
    private function runs(string $receiver, array $commandLines): bool
    {
        return array_any($commandLines, static function (array $arguments) use ($receiver): bool {
            $consume = array_search(self::CONSUME, $arguments, true);

            return false !== $consume && in_array($receiver, array_slice($arguments, $consume + 1), true);
        });
    }
}
