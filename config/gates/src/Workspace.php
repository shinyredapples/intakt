<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class Workspace
{
    private const array DEFAULT_BASES = ['origin/main', 'main'];
    private const array GATE_DEFINITIONS = ['config/gates/', 'bin/check', 'composer.json', 'composer.lock', 'phpunit.dist.xml', 'phpunit.gates.xml'];

    private Scope $scope;

    public function __construct(
        public string $root,
        private string $home,
        private Mode $mode,
        private ?string $requestedBase,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
        $this->scope = new Scope($root, $runner);
    }

    public function tool(string $name): string
    {
        return $this->home.'/vendor/bin/'.$name;
    }

    public function console(): string
    {
        return $this->home.'/bin/console';
    }

    public function config(string $file): string
    {
        return $this->home.'/config/gates/'.$file;
    }

    public function hasGit(): bool
    {
        return file_exists($this->root.'/.git');
    }

    public function hasTool(string $name): bool
    {
        return is_file($this->tool($name));
    }

    /**
     * @return list<string>
     */
    public function phpFiles(): array
    {
        if (Mode::ChangedFilesOnly === $this->mode) {
            return $this->changedOrAllPhpFiles();
        }

        return $this->allPhpFilesOrFail();
    }

    /**
     * @return list<string>
     */
    public function repositoryFiles(): array
    {
        return $this->scope->repositoryFiles();
    }

    public function isFast(): bool
    {
        return Mode::ChangedFilesOnly === $this->mode;
    }

    public function baseTip(): ?string
    {
        foreach ($this->candidateBases() as $base) {
            $result = $this->git(['rev-parse', '--verify', '--quiet', $base.'^{commit}']);

            if ($result->succeeded()) {
                return trim($result->output);
            }
        }

        return null;
    }

    public function mergeBase(): ?string
    {
        foreach ($this->candidateBases() as $base) {
            $result = $this->git(['merge-base', $base, 'HEAD']);

            if ($result->succeeded()) {
                return trim($result->output);
            }
        }

        return null;
    }

    /**
     * @param list<string> $arguments
     */
    public function git(array $arguments): ProcessResult
    {
        return $this->runner->git($arguments, $this->root);
    }

    /**
     * @param list<string> $arguments
     */
    public function gitWithPathspecMagic(array $arguments): ProcessResult
    {
        return $this->runner->gitWithPathspecMagic($arguments, $this->root);
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function command(array $command, array $environment = []): ProcessResult
    {
        return $this->runner->run($command, $this->root, $environment);
    }

    /**
     * @template Name of array-key
     *
     * @param array<Name, array{list<string>, array<string, string|false>}> $commands
     *
     * @return array<Name, ProcessResult>
     */
    public function commandsConcurrently(array $commands): array
    {
        return $this->runner->runConcurrently($commands, $this->root);
    }

    /**
     * @return list<string>
     */
    private function changedOrAllPhpFiles(): array
    {
        $mergeBase = $this->mergeBase();

        if (null === $mergeBase) {
            throw new ScopeFailure('no base branch found, pass --base=<ref>');
        }

        if ($this->changesGateDefinitions($mergeBase)) {
            return $this->allPhpFilesOrFail();
        }

        return $this->scope->changedPhpFiles($mergeBase);
    }

    /**
     * @return non-empty-list<string>
     */
    private function allPhpFilesOrFail(): array
    {
        $files = $this->scope->allPhpFiles();

        if ([] === $files) {
            throw new ScopeFailure('no PHP files found in the tree');
        }

        return $files;
    }

    private function changesGateDefinitions(string $mergeBase): bool
    {
        foreach ($this->scope->changedPaths($mergeBase) as $path) {
            foreach (self::GATE_DEFINITIONS as $definition) {
                if (str_starts_with($path, $definition)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateBases(): array
    {
        return null === $this->requestedBase ? self::DEFAULT_BASES : [$this->requestedBase];
    }
}
