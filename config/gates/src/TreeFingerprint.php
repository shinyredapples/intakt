<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

final readonly class TreeFingerprint
{
    // Because a new shell sets these anew without changing what any gate sees.
    private const array VOLATILE_VARIABLES = ['_', 'OLDPWD', 'SHLVL'];

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $root,
        private string $home,
        private Options $options,
        private array $environment,
        private ProcessRunner $runner = new ProcessRunner(),
        private HardenedGitCall $hardened = new HardenedGitCall(),
    ) {
    }

    // Because a passed result may stand only for the very same inputs, any input that cannot be read gives no fingerprint, so the check runs.
    public function taken(): ?string
    {
        try {
            $parts = [
                ...$this->commits(),
                $this->treeHash(),
                ...$this->lockFiles(),
                ...$this->gateConfiguration(),
                ...$this->optionsAndRuntime(),
            ];
        } catch (RepositoryAttributesRefused|UnexpectedValueException) {
            return null;
        }

        if (in_array(false, $parts, true)) {
            return null;
        }

        // Because each part is prefixed by its length, no value can pass for the boundary between two parts.
        return hash('sha256', implode('', array_map(static fn (?string $part): string => null === $part ? 'n' : strlen($part).':'.$part, $parts)));
    }

    /**
     * @return list<string|false|null>
     *
     * @throws RepositoryAttributesRefused
     */
    // Because the commit subjects and the history secrets judge commits, not the tree, the same tree on another commit or base is another check.
    private function commits(): array
    {
        if (!file_exists($this->root.'/.git')) {
            return [false];
        }

        $workspace = new Workspace($this->root, $this->home, $this->options->mode, $this->options->base, $this->runner);
        $head = $workspace->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        return [$head->succeeded() ? trim($head->output) : false, $workspace->baseTip(), $workspace->mergeBase()];
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    // Because the tree as it is, uncommitted and untracked files included, is what the gates read, it is written from a copy of the index, so the real index never changes.
    private function treeHash(): string|false
    {
        $index = tempnam(sys_get_temp_dir(), 'intakt-check-index-');

        if (false === $index) {
            return false;
        }

        try {
            return $this->treeHashWith($index);
        } finally {
            @unlink($index);
            @unlink($index.'.lock');
        }
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    private function treeHashWith(string $index): string|false
    {
        $real = $this->realIndex();

        if (null === $real || !$this->copied($real, $index)) {
            return false;
        }

        // Because the check writes its report and its record under var/check, a tree that does not ignore var/ would otherwise change with every run.
        $tree = $this->git(['add', '--all', '--', '.', ':(exclude,literal)'.FullReport::FOLDER], $index)->succeeded() ? $this->git(['write-tree'], $index) : null;

        return true === $tree?->succeeded() ? trim($tree->output) : false;
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    private function realIndex(): ?string
    {
        $path = $this->runner->git(['rev-parse', '--git-path', 'index'], $this->root);

        if (!$path->succeeded()) {
            return null;
        }

        $real = trim($path->output);

        return str_starts_with($real, '/') ? $real : $this->root.'/'.$real;
    }

    // Because a repository without commits has no index yet, git then starts from an empty one.
    private function copied(string $real, string $index): bool
    {
        if (!file_exists($real)) {
            return @unlink($index);
        }

        return @copy($real, $index);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     */
    private function git(array $arguments, string $index): ProcessResult
    {
        // Because the only pathspecs here are the two written above, never a path from the repository.
        $call = $this->hardened->callWithPathspecMagic($arguments, $this->root);

        return $this->runner->run($call->command, $call->directory, [...$call->environment, 'GIT_INDEX_FILE' => $index]);
    }

    /**
     * @return list<string|false|null>
     */
    private function lockFiles(): array
    {
        return [$this->contentsOf($this->root.'/composer.lock'), $this->contentsOf($this->home.'/composer.lock')];
    }

    /**
     * @return list<string|false>
     */
    // Because the gates and their configuration come from the home of bin/check, which may be another tree than the one checked.
    private function gateConfiguration(): array
    {
        $files = [$this->home.'/bin/check'];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->home.'/config/gates', FilesystemIterator::SKIP_DOTS));

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);
        $parts = [];

        foreach ($files as $file) {
            $parts[] = $file;
            $parts[] = $this->contentsOf($file) ?? false;
        }

        return $parts;
    }

    private function contentsOf(string $file): string|false|null
    {
        if (!file_exists($file) && !is_link($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        return false === $contents ? false : hash('sha256', $contents);
    }

    /**
     * @return list<string>
     */
    // Because the environment gate judges the running PHP, and a variable can change what a gate or a test does.
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
