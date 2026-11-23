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

        $boundary = is_file($shallow) ? file_get_contents($shallow) : false;

        return false === $boundary ? throw new UnexpectedValueException('cannot read the shallow file') : $boundary;
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
        $found = [new TreeWalk([self::GIT_FOLDER, ...self::WRITTEN_BY_THE_CHECK], $settledBefore)->entries($root, '', [$root])];

        $walk = new TreeWalk([], $settledBefore);

        foreach ($this->gateInputsAmongWhatTheCheckWrites($root) as $path) {
            $found[] = $walk->entries($root.'/'.$path, $path, [$root]);

            // Because a link that leads nowhere already shows by the missing entries behind it.
            if (is_link($root.'/'.$path) && file_exists($root.'/'.$path)) {
                $found[] = $walk->foldersOnTheRoute($root.'/'.$path, $path, $root);
            }

            foreach ($this->foldersAbove($path) as $folder) {
                $found[] = $walk->folderAlone($root.'/'.$folder, $folder, $root);
            }
        }

        return $this->sealed($found);
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
        // Because each part is prefixed by its length, no value can pass for the boundary between two parts.
        return hash('sha256', implode('', array_map(static fn (?string $part): string => null === $part ? 'n' : strlen($part).':'.$part, $parts)));
    }
}
