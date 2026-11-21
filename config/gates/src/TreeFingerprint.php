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

    // Because git keeps its own state there, and what the gates judge of it (the commits and the index) is part of the fingerprint by itself.
    private const string GIT_FOLDER = '.git';

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
            return $this->joined([
                ...$this->commits(),
                $this->index(),
                $this->checkedTree($settledBefore),
                ...$this->homeInputs($settledBefore),
                ...$this->optionsAndRuntime(),
            ]);
        } catch (RepositoryAttributesRefused|ScopeFailure|UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param list<?string> $parts
     */
    private function joined(array $parts): string
    {
        // Because each part is prefixed by its length, no value can pass for the boundary between two parts.
        return hash('sha256', implode('', array_map(static fn (?string $part): string => null === $part ? 'n' : strlen($part).':'.$part, $parts)));
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

        return [trim($head->output), $workspace->baseTip(), $workspace->mergeBase()];
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws UnexpectedValueException
     */
    // Because the fast check takes its files from the index too, a staged edit is another check even when the working file is back as it was; the listing reads the index and never writes it or any object.
    private function index(): string
    {
        $staged = $this->runner->git(['ls-files', '--stage', '-z'], $this->root);

        if (!$staged->succeeded()) {
            throw new UnexpectedValueException('cannot list the index');
        }

        return $staged->output;
    }

    /**
     * @throws ScopeFailure
     * @throws UnexpectedValueException
     */
    // Because the gates read files that git ignores (vendor/, .env.*.local, phpunit.xml) and ignore rules differ between the gates and git, every file counts, ignored or not.
    private function checkedTree(?int $settledBefore): string
    {
        $root = $this->real($this->root);
        $found = [$this->entries($root, '', [self::GIT_FOLDER, ...self::WRITTEN_BY_THE_CHECK], $settledBefore, [$root])];

        foreach ($this->gateInputsAmongWhatTheCheckWrites($root) as $path) {
            $found[] = $this->entries($root.'/'.$path, $path, [], $settledBefore, [$root]);
        }

        return $this->sealed($found);
    }

    /**
     * @return list<string>
     *
     * @throws ScopeFailure
     */
    // Because the gates take their files from this very listing, a path the check writes is left out only while the listing does not name it: a tracked file under var/, or an untracked one the committed .gitignore leaves in, counts like any other file.
    private function gateInputsAmongWhatTheCheckWrites(string $root): array
    {
        $inputs = [];

        foreach (new Scope($this->root, $this->runner)->repositoryFiles() as $listed) {
            // Because git lists a repository nested in the tree as a folder, with a slash at its end.
            $path = rtrim($listed, '/');
            $written = array_any(self::WRITTEN_BY_THE_CHECK, static fn (string $name): bool => $path === $name || str_starts_with($path, $name.'/'));

            // Because the index, which is part of the fingerprint, already tells a tracked file that is gone.
            if ($written && (file_exists($root.'/'.$path) || is_link($root.'/'.$path))) {
                $inputs[] = $path;
            }
        }

        return $inputs;
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

        return array_map(fn (string $input): ?string => file_exists($home.'/'.$input) || is_link($home.'/'.$input) ? $this->sealed([$this->entries($home.'/'.$input, $input, [], $settledBefore, [$home])]) : null, self::HOME_INPUTS);
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
     * @param iterable<iterable<string, string>> $found
     */
    private function sealed(iterable $found): string
    {
        $entries = [];

        foreach ($found as $group) {
            foreach ($group as $key => $entry) {
                $entries[$key] = $entry;
            }
        }

        ksort($entries, SORT_STRING);

        return $this->joined([...array_map(strval(...), array_keys($entries)), ...array_values($entries)]);
    }

    /**
     * @param list<string> $skipped keys left out, relative to the folder the walk started in
     * @param list<string> $entered the real folders this walk is inside of, through links
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    // Because a folder counts with its own times and inode, a file that came and went, or a folder moved away and back while the gates ran, shows even when every file is as it was.
    private function entries(string $path, string $key, array $skipped, ?int $settledBefore, array $entered): iterable
    {
        $status = @lstat($path);

        if (false === $status) {
            throw new UnexpectedValueException('cannot read '.$path);
        }

        if (null !== $settledBefore && $status['ctime'] >= $settledBefore) {
            throw new UnexpectedValueException('changed in the second the check started: '.$path);
        }

        if (is_link($path)) {
            yield $key => $this->described($status, $this->readOrFail($path, @readlink($path)));
            yield from $this->entriesBehindLink($path, $key, $settledBefore, $entered);

            return;
        }

        if (!is_dir($path)) {
            yield $key => $this->described($status, is_file($path) ? $this->readOrFail($path, @hash_file('sha256', $path)) : '');

            return;
        }

        yield $key => $this->described($status, '');
        $names = @scandir($path);

        if (false === $names) {
            throw new UnexpectedValueException('cannot read '.$path);
        }

        foreach (array_diff($names, ['.', '..']) as $name) {
            $child = '' === $key ? $name : $key.'/'.$name;

            if (!in_array($child, $skipped, true)) {
                yield from $this->entries($path.'/'.$name, $child, $skipped, $settledBefore, $entered);
            }
        }
    }

    /**
     * @param list<string> $entered
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    // Because the gates open a path through a link (a vendor/ that points at a shared install, a tracked path whose folder became a link), what is behind the link counts and not only where it points.
    private function entriesBehindLink(string $link, string $key, ?int $settledBefore, array $entered): iterable
    {
        if (!file_exists($link)) {
            return;
        }

        $target = $this->real($link);

        if (in_array($target, $entered, true)) {
            throw new UnexpectedValueException('a link leads back into its own folder: '.$link);
        }

        // Because no file name holds a zero byte, the key of what is behind a link never passes for a path beside it.
        yield from $this->entries($target, $key."\0", [], $settledBefore, [...$entered, $target]);
    }

    /**
     * @param array<int|string, int> $status
     */
    private function described(array $status, string $contents): string
    {
        return implode(' ', [$status['mode'], $status['size'], $status['mtime'], $status['ctime'], $status['ino'], $contents]);
    }

    /**
     * @throws UnexpectedValueException
     */
    private function readOrFail(string $path, string|false $read): string
    {
        if (false === $read) {
            throw new UnexpectedValueException('cannot read '.$path);
        }

        return $read;
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
