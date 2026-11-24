<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class Workspace
{
    private const array DEFAULT_BASES = ['origin/main', 'main'];
    private const array GATE_DEFINITIONS = ['config/gates/', 'bin/check', 'composer.json', 'composer.lock', 'phpunit.dist.xml', 'phpunit.gates.xml'];
    // Because the gate tests also read these: the CI image and workflows, the showcase script, the executables and the forge's copy of the hardened git call.
    private const array GATE_TEST_INPUTS = ['tests/Gates/', '.github/', 'docs/showcase/', 'bin/', 'src/Core/Git/'];
    // Because a service removed in configuration alone leaves its callers unchanged, a change to what builds the container analyses every PHP file: the kernel, `config/` and the `services.yaml` files it imports from `tests/`.
    private const array CONTAINER_CONFIGURATION = ['config/', 'src/Kernel.php'];
    private const string IMPORTED_SERVICES = '/services.yaml';

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

    public function instance(): string
    {
        return $this->home.'/bin/instance';
    }

    public function autoload(): string
    {
        return $this->home.'/vendor/autoload.php';
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

    // Because a gate that tells its tool whether it sees only some files must take that answer from the list it passes, not from a second read of a tree that may have changed in between.
    public function phpFileSelection(): PhpFileSelection
    {
        if (Mode::ChangedFilesOnly !== $this->mode) {
            return PhpFileSelection::everyFile($this->allPhpFilesOrFail());
        }

        $mergeBase = $this->mergeBase();

        if (null === $mergeBase) {
            throw new ScopeFailure('no base branch found, pass --base=<ref>');
        }

        if ($this->changesWhatEveryPhpFileDependsOn($mergeBase)) {
            return PhpFileSelection::everyFile($this->allPhpFilesOrFail());
        }

        return PhpFileSelection::of($this->scope->changedPhpFiles($mergeBase), $this->scope->allPhpFiles());
    }

    /**
     * @return list<string>
     */
    public function repositoryFiles(): array
    {
        return $this->scope->repositoryFiles();
    }

    /**
     * @return list<string>
     */
    public function untrackedPaths(): array
    {
        return $this->scope->untrackedPaths();
    }

    public function isPhpFileInScope(string $path): bool
    {
        return $this->scope->includes($path);
    }

    public function isFast(): bool
    {
        return Mode::ChangedFilesOnly === $this->mode;
    }

    public function changesGateTests(): bool
    {
        $mergeBase = $this->mergeBase();

        // Because without a base, or on the base itself (a push to main), the change is the whole tree.
        if (null === $mergeBase || $this->isHead($mergeBase)) {
            return true;
        }

        return $this->changesAnyOf($mergeBase, [...self::GATE_DEFINITIONS, ...self::GATE_TEST_INPUTS]);
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
     * @param list<string> $command
     */
    public function containedCommand(array $command, float $seconds): ProcessResult
    {
        return $this->runner->runWithin($command, $this->root, $seconds);
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

    private function changesWhatEveryPhpFileDependsOn(string $mergeBase): bool
    {
        return $this->changesAnyOf($mergeBase, [...self::GATE_DEFINITIONS, ...self::CONTAINER_CONFIGURATION]) || $this->changesImportedServices($mergeBase);
    }

    private function changesImportedServices(string $mergeBase): bool
    {
        return array_any($this->scope->changedPaths($mergeBase), static fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, self::IMPORTED_SERVICES));
    }

    private function isHead(string $commit): bool
    {
        $head = $this->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        return $head->succeeded() && trim($head->output) === $commit;
    }

    /**
     * @param list<string> $prefixes
     */
    private function changesAnyOf(string $mergeBase, array $prefixes): bool
    {
        foreach ($this->scope->changedPaths($mergeBase) as $path) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
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
