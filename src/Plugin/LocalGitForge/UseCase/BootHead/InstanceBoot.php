<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\BootHead;

use Closure;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

// Boots a commit once in a snapshot of it, with bin/instance taken from the harness commit, so the harness that judges a head is never the head's own.
final readonly class InstanceBoot
{
    private const string HARNESS = 'bin/instance';
    // Plugins stay on, because symfony/runtime writes vendor/autoload_runtime.php as one; a plugin is the commit's code like the code bin/instance boots.
    private const array INSTALL = ['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'];
    // The shell's codes for a command it cannot execute (126) or cannot find (127), because a missing or broken composer is the boot step's fault, never the commit's.
    private const array COULD_NOT_RUN = [126, 127];

    // bin/instance writes its status on descriptor 3 only; moved to stdout and its log to stderr, so no log line can pass for the status.
    private const array STATUS_ON_STDOUT = ['sh', '-c', 'exec "$@" 3>&1 1>&2', 'sh'];

    // Exact lines, as bin/probe accepts them, because a ready line with any other key or mode is outside the contract of bin/instance.
    private const array READY_LINES = ['{"status":"ready"}', '{"status":"ready","mode":"worker"}'];

    // An allowlist, so neither the install nor the commit's code gets a token of the booting install from its environment (COMPOSER_AUTH); HOME and COMPOSER_HOME are left out too, because Composer loads the install's auth.json from COMPOSER_HOME or from under HOME and a plugin of the commit reads those tokens through Composer's config, so both processes get a scratch home instead; COMPOSER_CACHE_DIR stays, so the package cache is shared when the install sets one.
    private const array INHERITED_VARIABLES = [
        'PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TMPDIR', 'COMPOSER_CACHE_DIR',
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy',
    ];

    private const string SCRATCH_HOME_SUFFIX = '-home';

    public function __construct(
        private int $installSeconds = 600,
        private int $bootSeconds = 180,
    ) {
    }

    // Every failure of the step itself is a crash outcome, because a boot step that throws would stop the review it only gates.
    public function of(BootAt $target, CommitId $harness): BootOutcome
    {
        try {
            $forge = $this->forgeFor($target->source);
            $instance = $forge->fileAt($harness, self::HARNESS);

            // Because a binary file is read by its size only, so placing it would write that name instead of the harness.
            if (null !== $instance->binaryBytes) {
                return BootOutcome::crashed(sprintf('%s is a binary file of %d bytes, not a harness.', self::HARNESS, $instance->binaryBytes));
            }

            return $forge->withSnapshotOf(
                $target->commit,
                fn (string $snapshot): BootOutcome => $this->withScratchHomeOf($snapshot, fn (): BootOutcome => $this->bootIn($snapshot, $instance)),
            );
            // Boundary: turns any crash of the boot into a crashed outcome, so the caller records it instead of failing with it.
        } catch (Throwable $crash) {
            return BootOutcome::crashed($crash->getMessage());
        }
    }

    private function forgeFor(ChangeSource $source): LocalGitForge
    {
        return new LocalGitForge(LocalRepository::existingAt($source->repository, $source->base), new NameStatusParser());
    }

    // The harness is placed after the install, so nothing the install writes into bin can replace it.
    private function bootIn(string $snapshot, FileContent $harness): BootOutcome
    {
        $install = $this->run(self::INSTALL, $snapshot, $this->installSeconds);

        if (!$install instanceof Process) {
            return BootOutcome::failed(sprintf('composer install passed its time limit of %d seconds.', $this->installSeconds));
        }

        if (in_array($install->getExitCode(), self::COULD_NOT_RUN, true)) {
            return BootOutcome::crashed('composer could not run: '.trim($install->getErrorOutput()));
        }

        if (!$install->isSuccessful()) {
            return BootOutcome::failed('composer install failed: '.trim($install->getErrorOutput()));
        }

        if (!$this->hasPlainBinFolder($snapshot)) {
            return BootOutcome::failed('The commit has a bin that is not a plain folder, so the harness cannot be placed in it.');
        }

        $this->placeHarness($snapshot, $harness);

        $boot = $this->run([...self::STATUS_ON_STDOUT, PHP_BINARY, self::HARNESS], $snapshot, $this->bootSeconds);

        if (!$boot instanceof Process) {
            return BootOutcome::failed(sprintf('%s passed its time limit of %d seconds.', self::HARNESS, $this->bootSeconds));
        }

        return $this->outcomeOf($boot);
    }

    // One empty home beside each snapshot, removed with it, so what Composer or the commit's code writes there (an auth.json, a config) reaches neither the install's home nor the next boot; without a filesystem sandbox (a later part) the commit's code can still compute the install's real home and read it, only what the environment and Composer hand over is removed here.
    /**
     * @param Closure(): BootOutcome $work
     */
    private function withScratchHomeOf(string $snapshot, Closure $work): BootOutcome
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->homeOf($snapshot), 0o700);

        try {
            return $work();
        } finally {
            $filesystem->remove($this->homeOf($snapshot));
        }
    }

    private function homeOf(string $snapshot): string
    {
        return $snapshot.self::SCRATCH_HOME_SUFFIX;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $snapshot, int $seconds): ?Process
    {
        $process = new Process($command, $snapshot, $this->environment($this->homeOf($snapshot)), timeout: $seconds);

        // Boundary: a commit's own process that passes its limit is the head's failure (null), so a head that hangs cannot switch the gate off with a crash.
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $process;
    }

    // A link is refused, because writing the harness through it would change a file outside the snapshot.
    private function hasPlainBinFolder(string $snapshot): bool
    {
        $bin = $snapshot.'/'.dirname(self::HARNESS);

        return !is_link($bin) && (is_dir($bin) || !file_exists($bin));
    }

    // Removed before it is written, because the commit's own bin/instance may be a link, which writing would follow.
    private function placeHarness(string $snapshot, FileContent $harness): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($snapshot.'/'.self::HARNESS);
        $filesystem->dumpFile($snapshot.'/'.self::HARNESS, $harness->content);
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(string $home): array
    {
        // Process adds every variable of getenv() and $_ENV that is not named here, so each of them is named as unset.
        $environment = array_fill_keys(array_map(strval(...), array_keys(getenv() + $_ENV)), false);

        foreach (self::INHERITED_VARIABLES as $name) {
            $value = getenv($name);

            if (false !== $value) {
                $environment[$name] = $value;
            }
        }

        return [...$environment, 'HOME' => $home, 'COMPOSER_HOME' => $home];
    }

    private function outcomeOf(Process $boot): BootOutcome
    {
        $line = trim($boot->getOutput());
        $status = json_decode($line, true);

        return match (true) {
            !is_array($status) => BootOutcome::crashed('bin/instance reported no status line.'),
            in_array($line, self::READY_LINES, true) && $boot->isSuccessful() => BootOutcome::booted(),
            null !== ($reason = $this->failureReasonOf($status)) => BootOutcome::failed($reason),
            default => BootOutcome::crashed('bin/instance reported a status line it does not define: '.$line),
        };
    }

    /**
     * @param array<mixed> $status
     */
    private function failureReasonOf(array $status): ?string
    {
        $reason = $status['reason'] ?? null;
        // Strict array comparison, so a failed line with any other key, key order or mode is outside the contract.
        $defined = [['status' => 'failed', 'reason' => $reason], ['status' => 'failed', 'mode' => 'worker', 'reason' => $reason]];

        return in_array($status, $defined, true) && is_string($reason) && '' !== trim($reason) ? $reason : null;
    }
}
