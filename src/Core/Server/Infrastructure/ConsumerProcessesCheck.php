<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The workers cannot report that they are gone, so the doctor looks for the consumers docker/entrypoint.sh starts in the process list, which only reads (decisions D2 and D3 of #3827).
final readonly class ConsumerProcessesCheck
{
    private const string CONSUME = 'messenger:consume';
    private const string CONSOLE = '/bin/console';
    // Keyed by receiver, because the receiver argument is what tells the consumers docker/entrypoint.sh starts apart.
    /** @var array<string, string> */
    private const array KINDS = [
        'async' => 'no async worker is running, so no review or build moves',
        'scheduler_watcher' => 'the scheduler worker is not running, so pull requests are not watched',
    ];
    private const string MISSING_FIX = 'restart the container with docker compose restart intakt; outside the container, start them with messenger:consume async and messenger:consume scheduler_watcher scheduler_retention';
    private const string CANNOT_RUN_FIX = 'run the doctor inside the container: docker compose exec intakt php bin/console intakt:doctor';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
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
     * @return array<string, list<string>>|null the arguments of each process, keyed by its folder under the process root
     */
    private function commandLines(): ?array
    {
        $files = is_dir($this->processRoot) && is_readable($this->processRoot) ? glob($this->processRoot.'/[0-9]*/cmdline') : false;
        if (false === $files || [] === $files) {
            return null;
        }
        $folders = array_map(dirname(...), $files);
        // Quiet, because a process may end between the listing and the read.
        $read = array_filter(array_combine($folders, array_map(static fn (string $file): string|false => @file_get_contents($file), $files)), is_string(...));
        if ([] === $read) {
            // Not that the workers are gone, because a list whose every command line is hidden says nothing about them.
            return null;
        }

        return array_map(
            static fn (string $line): array => explode("\0", rtrim($line, "\0")),
            array_filter($read, static fn (string $line): bool => '' !== $line),
        );
    }

    /**
     * @param array<string, list<string>> $commandLines
     */
    private function runs(string $receiver, array $commandLines): bool
    {
        return array_any($commandLines, fn (array $arguments, string $folder): bool => $this->consumes($receiver, $arguments, $folder));
    }

    /**
     * @param list<string> $arguments
     */
    private function consumes(string $receiver, array $arguments, string $folder): bool
    {
        $consume = array_search(self::CONSUME, $arguments, true);

        // Only PHP running the console counts, because a grep or ps that searches for the worker carries the same words.
        return is_int($consume) && $consume >= 2
            && str_contains(basename($arguments[0]), 'php')
            && in_array($receiver, array_slice($arguments, $consume + 1), true)
            && $this->isThisInstallsConsole($arguments[$consume - 1], $folder);
    }

    // Compared as resolved paths, because another program's script named console, or another install's, is not one of this install's workers.
    private function isThisInstallsConsole(string $script, string $folder): bool
    {
        $own = realpath($this->projectDir.self::CONSOLE);
        if (false === $own) {
            return false;
        }
        if (str_starts_with($script, '/')) {
            return realpath($script) === $own;
        }
        // Quiet, because the working directory of another user's process cannot be read; such a script cannot be placed, so it does not count.
        $workingDirectory = @readlink($folder.'/cwd');

        return false !== $workingDirectory && realpath($workingDirectory.'/'.$script) === $own;
    }
}
