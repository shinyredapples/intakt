<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\BootHead;

use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

// Boots a commit once in a snapshot of it, with bin/instance taken from the harness commit, so the harness that judges a head is never the head's own.
final readonly class InstanceBoot
{
    private const string HARNESS = 'bin/instance';
    // Plugins stay on, because symfony/runtime writes vendor/autoload_runtime.php as one; a plugin is the commit's code like the code bin/instance boots.
    private const array INSTALL = ['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'];
    private const int INSTALL_SECONDS = 600;
    private const int BOOT_SECONDS = 180;

    // bin/instance writes its status on descriptor 3 only; moved to stdout and its log to stderr, so no log line can pass for the status.
    private const array STATUS_ON_STDOUT = ['sh', '-c', 'exec "$@" 3>&1 1>&2', 'sh'];

    // An allowlist, so neither the install nor the commit's code sees a token of the install that boots it; COMPOSER_AUTH is left out for that reason.
    private const array INHERITED_VARIABLES = [
        'PATH', 'HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TMPDIR', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR',
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy',
    ];

    // Every failure of the step itself is a crash outcome, because a boot step that throws would stop the review it only gates.
    public function of(BootAt $target, CommitId $harness): BootOutcome
    {
        try {
            $forge = $this->forgeFor($target->source);
            $instance = $forge->fileAt($harness, self::HARNESS);

            return $forge->withSnapshotOf($target->commit, fn (string $snapshot): BootOutcome => $this->bootIn($snapshot, $instance));
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
        $install = $this->run(self::INSTALL, $snapshot, self::INSTALL_SECONDS);

        if (!$install->isSuccessful()) {
            return BootOutcome::failed('composer install failed: '.trim($install->getErrorOutput()));
        }

        if (!$this->hasPlainBinFolder($snapshot)) {
            return BootOutcome::failed('The commit has a bin that is not a plain folder, so the harness cannot be placed in it.');
        }

        $this->placeHarness($snapshot, $harness);

        return $this->outcomeOf($this->run([...self::STATUS_ON_STDOUT, PHP_BINARY, self::HARNESS], $snapshot, self::BOOT_SECONDS));
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
     * @param list<string> $command
     */
    private function run(array $command, string $snapshot, int $seconds): Process
    {
        $process = new Process($command, $snapshot, $this->environment(), timeout: $seconds);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        // Process adds every variable of getenv() and $_ENV that is not named here, so each of them is named as unset.
        $environment = array_fill_keys(array_map(strval(...), array_keys(getenv() + $_ENV)), false);

        foreach (self::INHERITED_VARIABLES as $name) {
            $value = getenv($name);

            if (false !== $value) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function outcomeOf(Process $boot): BootOutcome
    {
        $line = trim($boot->getOutput());
        $status = json_decode($line, true);

        return match (true) {
            !is_array($status) => BootOutcome::crashed('bin/instance reported no status line.'),
            'ready' === ($status['status'] ?? null) && $boot->isSuccessful() => BootOutcome::booted(),
            'failed' === ($status['status'] ?? null) && is_string($status['reason'] ?? null) => BootOutcome::failed($status['reason']),
            default => BootOutcome::crashed('bin/instance reported a status line it does not define: '.$line),
        };
    }
}
