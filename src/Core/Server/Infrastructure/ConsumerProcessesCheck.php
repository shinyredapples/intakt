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
    private const string UNVERIFIED_FIX = 'run the doctor as the user that runs the workers, so it may read their working directory (in the compose stack: docker compose exec intakt php bin/console intakt:doctor)';

    // The doctor's own PID is a parameter only so a test can place it in a fake process list; null means this process.
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private string $processRoot = '/proc',
        private ?int $ownProcessId = null,
    ) {
    }

    public function run(): DoctorCheckVerdict
    {
        $commandLines = $this->commandLines();
        if (null === $commandLines) {
            return DoctorCheckVerdict::failed('cannot run (the process list under '.$this->processRoot.' cannot be read)', self::CANNOT_RUN_FIX);
        }
        $receivers = array_keys(self::KINDS);

        return $this->verdict(array_combine($receivers, array_map(fn (string $receiver): WorkerProcess => $this->found($receiver, $commandLines), $receivers)));
    }

    // Keyed by the process's folder, because a relative script is placed from the working directory that folder links to.
    /**
     * @return array<string, list<string>>|null
     */
    private function commandLines(): ?array
    {
        $files = is_dir($this->processRoot) && is_readable($this->processRoot) ? glob($this->processRoot.'/[0-9]*/cmdline') : false;
        if (false === $files || [] === $files) {
            return null;
        }
        // The doctor's own command line is left out, because under hidepid it stays readable while every worker's is hidden (decision 2 of #3827).
        $files = array_values(array_filter($files, fn (string $file): bool => basename(dirname($file)) !== (string) ($this->ownProcessId ?? getmypid())));
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
    private function found(string $receiver, array $commandLines): WorkerProcess
    {
        $processes = array_map(fn (array $arguments, string $folder): WorkerProcess => $this->consumer($receiver, $arguments, $folder), $commandLines, array_keys($commandLines));

        return match (true) {
            in_array(WorkerProcess::ThisInstalls, $processes, true) => WorkerProcess::ThisInstalls,
            in_array(WorkerProcess::Unverifiable, $processes, true) => WorkerProcess::Unverifiable,
            default => WorkerProcess::Other,
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function consumer(string $receiver, array $arguments, string $folder): WorkerProcess
    {
        $consume = array_search(self::CONSUME, $arguments, true);

        // Only PHP running the console counts, because a grep or ps that searches for the worker carries the same words.
        return is_int($consume) && $consume >= 2
            && str_contains(basename($arguments[0]), 'php')
            && in_array($receiver, array_slice($arguments, $consume + 1), true)
            ? $this->console($arguments[$consume - 1], $folder)
            : WorkerProcess::Other;
    }

    // Compared as resolved paths, because another program's script named console, or another install's, is not one of this install's workers.
    private function console(string $script, string $folder): WorkerProcess
    {
        $own = realpath($this->projectDir.self::CONSOLE);
        if (false === $own) {
            return WorkerProcess::Other;
        }
        if (str_starts_with($script, '/')) {
            return realpath($script) === $own ? WorkerProcess::ThisInstalls : WorkerProcess::Other;
        }
        // Quiet, because the working directory of another user's process cannot be read.
        $workingDirectory = @readlink($folder.'/cwd');
        if (false === $workingDirectory) {
            // Not stopped, because a relative bin/console with an unreadable folder may be this install's worker (D5).
            return str_ends_with('/'.$script, self::CONSOLE) ? WorkerProcess::Unverifiable : WorkerProcess::Other;
        }

        return realpath($workingDirectory.'/'.$script) === $own ? WorkerProcess::ThisInstalls : WorkerProcess::Other;
    }

    /**
     * @param array<string, WorkerProcess> $found
     */
    private function verdict(array $found): DoctorCheckVerdict
    {
        $failing = array_filter($found, static fn (WorkerProcess $process): bool => WorkerProcess::ThisInstalls !== $process);
        if ([] === $failing) {
            return DoctorCheckVerdict::passed();
        }
        $problems = array_map(
            static fn (WorkerProcess $process, string $receiver): string => WorkerProcess::Unverifiable === $process
                ? 'cannot run (the doctor may not read the working directory of a process that may be the '.$receiver.' worker)'
                : self::KINDS[$receiver],
            $failing,
            array_keys($failing),
        );
        $fixes = array_filter([
            in_array(WorkerProcess::Unverifiable, $failing, true) ? self::UNVERIFIED_FIX : '',
            in_array(WorkerProcess::Other, $failing, true) ? self::MISSING_FIX : '',
        ]);

        return DoctorCheckVerdict::failed(implode('; ', $problems), implode('; ', $fixes));
    }
}
