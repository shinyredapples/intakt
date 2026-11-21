<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

final readonly class TreeFingerprint
{
    // Because a new shell sets these anew without changing what any gate sees.
    private const array VOLATILE_VARIABLES = ['_', 'OLDPWD', 'SHLVL'];

    // Because the check itself writes these while its gates run (its report and record, the test result cache, the reference a debug kernel writes on every boot), and no gate judges the tree by them.
    private const array WRITTEN_BY_THE_CHECK = ['.git', 'var', '.phpunit.cache', 'config/reference.php', 'config/schema.json'];

    // Because the gates run their tools from the home of bin/check, which may be another tree than the one checked.
    private const array HOME_INPUTS = ['bin/check', 'config/gates', 'composer.lock', 'vendor'];

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $root,
        private string $home,
        private Options $options,
        private array $environment,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    // Because a passed result may stand only for the very same inputs, any input that cannot be read gives no fingerprint, so the check runs; a file changed at or after $settledBefore gives none either, since a change within that second leaves no trace in its times.
    public function taken(?int $settledBefore = null): ?string
    {
        if (!file_exists($this->root.'/.git')) {
            return null;
        }

        clearstatcache();

        try {
            $parts = [
                ...$this->commits(),
                $this->index(),
                $this->files($this->root, self::WRITTEN_BY_THE_CHECK, $settledBefore),
                ...$this->homeInputs($settledBefore),
                ...$this->optionsAndRuntime(),
            ];
        } catch (RepositoryAttributesRefused|UnexpectedValueException) {
            return null;
        }

        if (in_array(false, $parts, true)) {
            return null;
        }

        return $this->joined($parts);
    }

    /**
     * @param list<string|false|null> $parts
     */
    private function joined(array $parts): string
    {
        // Because each part is prefixed by its length, no value can pass for the boundary between two parts.
        return hash('sha256', implode('', array_map(static fn (string|false|null $part): string => null === $part ? 'n' : strlen((string) $part).':'.$part, $parts)));
    }

    /**
     * @return list<string|false|null>
     *
     * @throws RepositoryAttributesRefused
     */
    // Because the commit subjects and the history secrets judge commits, not the tree, the same tree on another commit or base is another check.
    private function commits(): array
    {
        $workspace = new Workspace($this->root, $this->home, $this->options->mode, $this->options->base, $this->runner);
        $head = $workspace->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        return [$head->succeeded() ? trim($head->output) : false, $workspace->baseTip(), $workspace->mergeBase()];
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    // Because the fast check takes its files from the index too, a staged edit is another check even when the working file is back as it was; the listing reads the index and never writes it or any object.
    private function index(): string|false
    {
        $staged = $this->runner->git(['ls-files', '--stage', '-z'], $this->root);

        return $staged->succeeded() ? $staged->output : false;
    }

    /**
     * @return list<string|false|null>
     */
    private function homeInputs(?int $settledBefore): array
    {
        $home = realpath($this->home);

        if (false === $home) {
            return [false];
        }

        if (realpath($this->root) === $home) {
            return [];
        }

        return array_map(fn (string $input): string|false|null => file_exists($home.'/'.$input) || is_link($home.'/'.$input) ? $this->files($home.'/'.$input, [], $settledBefore) : null, self::HOME_INPUTS);
    }

    /**
     * @param list<string> $skipped
     *
     * @throws UnexpectedValueException
     */
    // Because the gates read files that git ignores (vendor/, .env.*.local, phpunit.xml) and ignore rules differ between the gates and git, every file counts, ignored or not; its times and inode tell a file rewritten while the gates ran even when its bytes are back as they were.
    private function files(string $path, array $skipped, ?int $settledBefore): string|false
    {
        $entries = [];

        foreach ($this->entriesUnder($path, $skipped) as $relative => $file) {
            $entry = $this->entry($file, $settledBefore);

            if (false === $entry) {
                return false;
            }

            $entries[$relative] = $entry;
        }

        ksort($entries, SORT_STRING);

        return $this->joined([...array_keys($entries), ...array_values($entries)]);
    }

    /**
     * @param list<string> $skipped
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    private function entriesUnder(string $path, array $skipped): iterable
    {
        if (is_link($path) || !is_dir($path)) {
            yield basename($path) => $path;

            return;
        }

        $prefix = strlen($path) + 1;
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $kept = new RecursiveCallbackFilterIterator($directory, static fn (SplFileInfo $entry): bool => !in_array(substr($entry->getPathname(), $prefix), $skipped, true));

        foreach (new RecursiveIteratorIterator($kept) as $entry) {
            if ($entry instanceof SplFileInfo) {
                yield substr($entry->getPathname(), $prefix) => $entry->getPathname();
            }
        }
    }

    private function entry(string $file, ?int $settledBefore): string|false
    {
        $status = @lstat($file);

        if (false === $status || (null !== $settledBefore && $status['ctime'] >= $settledBefore)) {
            return false;
        }

        $contents = match (true) {
            is_link($file) => @readlink($file),
            is_file($file) => @hash_file('sha256', $file),
            default => '',
        };

        return false === $contents ? false : implode(' ', [$status['mode'], $status['size'], $status['mtime'], $status['ctime'], $status['ino'], $contents]);
    }

    /**
     * @return list<string>
     */
    // Because the environment gate judges the running PHP, and a variable can change what a gate or a test does; asking for reuse changes no gate, so a run without it may be reused.
    private function optionsAndRuntime(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);
        $environment = array_diff_key($this->environment, array_flip(self::VOLATILE_VARIABLES));
        ksort($environment);

        return [
            serialize([$this->options->mode->name, $this->options->only, $this->options->skip, $this->options->base, $this->options->testMerge]),
            serialize([PHP_VERSION, $extensions, ini_get_all(null, false)]),
            serialize($environment),
        ];
    }
}
