<?php

declare(strict_types=1);

namespace Intakt\Gates;

use UnexpectedValueException;

final readonly class TreeFingerprint
{
    // Because a new shell sets these anew without changing what any gate sees.
    private const array VOLATILE_VARIABLES = ['_', 'OLDPWD', 'SHLVL'];

    // Because the check itself writes these while its gates run (its report and record, the test result cache, the reference a debug kernel writes); they are left out only where the gates' own file listing does not name them.
    private const array WRITTEN_BY_THE_CHECK = ['var', '.phpunit.cache', 'config/reference.php', 'config/schema.json'];

    // Because the gates take their files with this same ignore option (Scope::repositoryFiles()).
    private const string GITIGNORE_ONLY = '--exclude-per-directory=.gitignore';

    // Because git keeps its own state there, and what the gates judge of it (the commits and the index) is part of the fingerprint by itself.
    private const string GIT_FOLDER = '.git';

    // Because the gates run their tools from the home of bin/check, which may be another tree than the one checked.
    private const array HOME_INPUTS = ['bin/check', 'config/gates', 'composer.lock', 'vendor'];

    // Because every entry is held in memory until the walk ends and PHP cannot catch running out of it, a walk past this many entries gives no fingerprint: about four times Intakt's own tree with vendor/ installed, and still within PHP's default limit of 128 MB.
    private const int MOST_ENTRIES = 100_000;

    // Because long paths fill that memory with far fewer entries, the bytes of the keys and entries are bounded too: several times Intakt's own tree with vendor/ installed.
    private const int MOST_BYTES = 16 * 1024 * 1024;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $root,
        private string $home,
        private Options $options,
        private array $environment,
        private ProcessRunner $runner = new ProcessRunner(),
        private int $mostEntries = self::MOST_ENTRIES,
        private int $mostBytes = self::MOST_BYTES,
    ) {
    }

    // Because a passed result may stand only for the very same inputs, any input that cannot be read gives no fingerprint, so the check runs; a file changed at or after $settledBefore gives none either, since a change within that second leaves no trace in its times.
    public function taken(?int $settledBefore = null): ?string
    {
        if (!file_exists($this->root.'/.git')) {
            return null;
        }

        // With the realpath cache too: a link along a route that another process retargeted since the last fingerprint would otherwise still resolve to its old target.
        clearstatcache(true);

        try {
            return $this->joined([
                ...$this->commits(),
                $this->index(),
                $this->checkedTree($settledBefore),
                ...$this->homeInputs($settledBefore),
                ...$this->optionsAndRuntime(),
            ]);
        } catch (RepositoryAttributesRefused|UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @return list<?string>
     *
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the commit subjects and the history secrets judge commits, not the tree, the same tree on another commit or base is another check.
    private function commits(): array
    {
        $workspace = new Workspace($this->root, $this->home, $this->options->mode, $this->options->base, $this->runner);
        $head = $workspace->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        if (!$head->succeeded()) {
            throw new UnexpectedValueException('no HEAD commit');
        }

        return [trim($head->output), $workspace->baseTip(), $workspace->mergeBase(), $this->shallowBoundary()];
    }

    /**
     * @throws UnexpectedValueException
     */
    // Because a shallow checkout hides the commits beyond its boundary from the history gates, deepening it is another check although HEAD and the tree stay the same.
    private function shallowBoundary(): ?string
    {
        $located = $this->runner->git(['rev-parse', '--git-path', 'shallow'], $this->root);

        if (!$located->succeeded()) {
            throw new UnexpectedValueException('cannot locate the shallow file');
        }

        $path = trim($located->output);
        $shallow = str_starts_with($path, '/') ? $path : $this->root.'/'.$path;

        if (!file_exists($shallow) && !is_link($shallow)) {
            return null;
        }

        // Because a boundary of any size would otherwise be read whole into memory, one byte past the limit is enough to refuse a longer one.
        $boundary = is_file($shallow) ? file_get_contents($shallow, length: max(0, $this->mostBytes) + 1) : false;

        if (false === $boundary) {
            throw new UnexpectedValueException('cannot read the shallow file');
        }

        $this->expectWithinLimits(0, strlen($boundary));

        return $boundary;
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the fast check takes its files from the index too, a staged edit is another check even when the working file is back as it was; the listing reads the index and never writes it or any object.
    private function index(): string
    {
        $hash = hash_init('sha256');

        foreach ($this->listed(['ls-files', '--stage', '-z']) as $part) {
            hash_update($hash, $part);
        }

        return hash_final($hash);
    }

    /**
     * @param list<string> $arguments
     *
     * @return iterable<string>
     *
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because a listing of any length must cost no more memory than the entries may, git is stopped once it printed more bytes than that.
    private function listed(array $arguments): iterable
    {
        $bytes = 0;

        foreach ($this->runner->gitInParts($arguments, $this->root) as $part) {
            $bytes += strlen($part);
            $this->expectWithinLimits(0, $bytes);

            yield $part;
        }
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the gates read files that git ignores (vendor/, .env.*.local, phpunit.xml) and ignore rules differ between the gates and git, every file counts, ignored or not.
    private function checkedTree(?int $settledBefore): string
    {
        return $this->sealed($this->foundInTheCheckedTree($this->real($this->root), $settledBefore));
    }

    /**
     * @throws UnexpectedValueException
     */
    private function real(string $path): string
    {
        $real = realpath($path);

        if (false === $real) {
            throw new UnexpectedValueException('cannot resolve '.$path);
        }

        return $real;
    }

    /**
     * @return iterable<iterable<string, string>>
     *
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the walk stops once it counted too many entries or bytes, each part is started only when the one before it is counted.
    private function foundInTheCheckedTree(string $root, ?int $settledBefore): iterable
    {
        yield new TreeWalk([self::GIT_FOLDER, ...self::WRITTEN_BY_THE_CHECK], $settledBefore)->entries($root, '', [$root]);

        $walk = new TreeWalk([], $settledBefore);

        foreach ($this->gateInputsAmongWhatTheCheckWrites($root) as $path) {
            yield $walk->entries($root.'/'.$path, $path, [$root]);

            if (is_link($root.'/'.$path)) {
                yield $walk->foldersOnTheRoute($root.'/'.$path, $path, $root);
            }

            foreach ($this->foldersAbove($path) as $folder) {
                yield $walk->folderAlone($root.'/'.$folder, $folder, $root);
            }
        }
    }

    /**
     * @return iterable<string>
     *
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the gates take their files from this very listing (Scope::repositoryFiles(), asked here only for these names), a path the check writes is left out only while the listing does not name it: a tracked file under var/, or an untracked one the committed .gitignore leaves in, counts like any other file.
    private function gateInputsAmongWhatTheCheckWrites(string $root): iterable
    {
        $rest = '';

        foreach ($this->listed(['ls-files', '-z', '--cached', '--others', self::GITIGNORE_ONLY, '--', ...self::WRITTEN_BY_THE_CHECK]) as $part) {
            $paths = explode("\0", $rest.$part);
            $rest = array_pop($paths);

            foreach ($paths as $listed) {
                // Because git lists a repository nested in the tree as a folder, with a slash at its end.
                $path = rtrim($listed, '/');

                // Because the index, which is part of the fingerprint, already tells a tracked file that is gone.
                if (file_exists($root.'/'.$path) || is_link($root.'/'.$path)) {
                    yield $path;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    // Because the walk skips the folders the check writes, a tracked file under them would not show that its folder was moved away and back while the gates ran; a folder the walk already counts gives the same entry again.
    private function foldersAbove(string $path): array
    {
        $folders = [];

        for ($folder = dirname($path); '.' !== $folder; $folder = dirname($folder)) {
            $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * @param iterable<iterable<string, string>> $found
     *
     * @throws UnexpectedValueException
     */
    private function sealed(iterable $found): string
    {
        $entries = $this->held($found);
        ksort($entries, SORT_STRING);

        // Because joining the entries first would hold all of them a second time, each is hashed by itself.
        $hash = hash_init('sha256');

        foreach ($entries as $key => $entry) {
            hash_update($hash, $this->framed(strval($key)));
            hash_update($hash, $this->framed($entry));
        }

        return hash_final($hash);
    }

    /**
     * @param iterable<iterable<string, string>> $found
     *
     * @return array<string, string>
     *
     * @throws UnexpectedValueException
     */
    private function held(iterable $found): array
    {
        $entries = [];
        $bytes = 0;

        foreach ($found as $group) {
            foreach ($group as $key => $entry) {
                $bytes += strlen($entry) + (isset($entries[$key]) ? -strlen($entries[$key]) : strlen($key));
                $entries[$key] = $entry;
                $this->expectWithinLimits(count($entries), $bytes);
            }
        }

        return $entries;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function expectWithinLimits(int $entries, int $bytes): void
    {
        if ($entries > $this->mostEntries || $bytes > $this->mostBytes) {
            throw new UnexpectedValueException('more than the fingerprint holds');
        }
    }

    /**
     * @return list<?string>
     *
     * @throws UnexpectedValueException
     */
    private function homeInputs(?int $settledBefore): array
    {
        $home = $this->real($this->home);

        if ($this->real($this->root) === $home) {
            return [];
        }

        return array_map(fn (string $input): ?string => file_exists($home.'/'.$input) || is_link($home.'/'.$input) ? $this->sealed([new TreeWalk([], $settledBefore)->entries($home.'/'.$input, $input, [$home])]) : null, self::HOME_INPUTS);
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

    /**
     * @param list<?string> $parts
     */
    private function joined(array $parts): string
    {
        return hash('sha256', implode('', array_map($this->framed(...), $parts)));
    }

    // Because each part is prefixed by its length, no value can pass for the boundary between two parts.
    private function framed(?string $part): string
    {
        return null === $part ? 'n' : strlen($part).':'.$part;
    }
}
