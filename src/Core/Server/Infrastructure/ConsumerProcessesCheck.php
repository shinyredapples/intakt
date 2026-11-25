<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// The workers cannot report that they are gone, so the doctor looks for the consumers docker/entrypoint.sh starts in the process list, which only reads (decisions D2 and D3 of #3827).
final readonly class ConsumerProcessesCheck
{
    private const string CONSUME = 'messenger:consume';
    // Keyed by receiver, because the receiver argument is what tells the consumers docker/entrypoint.sh starts apart.
    /** @var array<string, string> */
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

    public function run(): DoctorCheckVerdict
    {
        $commandLines = $this->commandLines();
        if (null === $commandLines) {
            return DoctorCheckVerdict::failed('cannot run (the process list under '.$this->processRoot.' cannot be read)', self::CANNOT_RUN_FIX);
        }
        $missing = array_keys(array_filter(self::KINDS, fn (string $problem, string $receiver): bool => !$this->runs($receiver, $commandLines), ARRAY_FILTER_USE_BOTH));

        return [] === $missing
            ? DoctorCheckVerdict::passed()
            : DoctorCheckVerdict::failed(implode('; ', array_map(static fn (string $receiver): string => self::KINDS[$receiver], $missing)), self::MISSING_FIX);
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
        $read = 0;
        foreach ($files as $file) {
            // Quiet, because a process may end between the listing and the read.
            $line = @file_get_contents($file);
            if (!is_string($line)) {
                continue;
            }
            ++$read;
            if ('' !== $line) {
                $lines[] = explode("\0", rtrim($line, "\0"));
            }
        }

        // Not a single readable command line means the list is hidden from the doctor, not that the workers are gone.
        return 0 === $read ? null : $lines;
    }

    /**
     * @param list<list<string>> $commandLines
     */
    private function runs(string $receiver, array $commandLines): bool
    {
        return array_any($commandLines, static fn (array $arguments): bool => self::consumes($receiver, $arguments));
    }

    /**
     * @param list<string> $arguments
     */
    private static function consumes(string $receiver, array $arguments): bool
    {
        $consume = array_search(self::CONSUME, $arguments, true);

        // Only PHP running bin/console counts, because a grep or ps that searches for the worker carries the same words.
        return is_int($consume) && $consume >= 2
            && str_contains(basename($arguments[0]), 'php')
            && 'console' === basename($arguments[$consume - 1])
            && in_array($receiver, array_slice($arguments, $consume + 1), true);
    }
}
