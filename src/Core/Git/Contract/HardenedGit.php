<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// config/gates/src/HardenedGitCall.php is the protected copy the gates use; tests/Gates/HardenedGitCallTest.php keeps this one equal to it, because a forge read must be as hard as a gate's.
final readonly class HardenedGit
{
    // Because command-line settings beat the repository's own config: an empty diff.external switches the external diff off, a signature check would start the repository's gpg program, an output encoding would rewrite what git prints, and ignoring case would hide a file beside one that differs only in case.
    private const array SETTINGS = [
        'core.hooksPath' => '/dev/null',
        'core.fsmonitor' => 'false',
        'diff.external' => '',
        'core.attributesFile' => '/dev/null',
        'log.showSignature' => 'false',
        'gpg.program' => '/dev/null',
        'gpg.ssh.program' => '/dev/null',
        'gpg.x509.program' => '/dev/null',
        'i18n.logOutputEncoding' => 'UTF-8',
        'core.ignoreCase' => 'false',
    ];

    // Git's empty tree in each object format, so no .gitattributes of the repository applies; a format git adds later gets SHA-1's, which git refuses there, so a call that reads attributes fails closed.
    private const array EMPTY_TREES = [
        'sha1' => '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
        'sha256' => '6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321',
    ];

    // Because git reads this file on top of every other attributes source and no option, setting or variable switches it off.
    private const string INFO_ATTRIBUTES = 'info/attributes';

    // A path that does not exist, because git reads a missing user config or graft file silently but dies on "/dev/null/.gitconfig".
    private const string NOWHERE = '/nonexistent';

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function call(string $directory, string ...$arguments): GitCall
    {
        // Pathspec magic would read ":odd.txt" as "odd.txt".
        return $this->callWith(['--literal-pathspecs'], array_values($arguments), $directory);
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function callWithPathspecMagic(string $directory, string ...$arguments): GitCall
    {
        // Because a gate writes glob pathspecs itself, which literal pathspecs refuse; never use it for a path from the repository.
        return $this->callWith([], array_values($arguments), $directory);
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function callInBareRepository(string $repository, string ...$arguments): GitCall
    {
        $repository = $this->absolute($repository);
        // Named and pinned as bare, because without a pinned work tree git would otherwise take the one core.worktree names, or the folder it runs in.
        $options = ['--literal-pathspecs', '--git-dir='.$repository, '--bare'];
        $emptyTree = $this->bareEmptyTree($options, $repository);

        return new GitCall(new GitCommand(...$this->command($options, array_values($arguments), $emptyTree)), $repository, $this->environmentOf($this->variables(null, $emptyTree)));
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function environment(string $directory): GitEnvironment
    {
        $directory = $this->absolute($directory);

        return $this->environmentOf($this->variables($directory, $this->emptyTree($directory)));
    }

    /**
     * @param list<string> $options
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function callWith(array $options, array $arguments, string $directory): GitCall
    {
        $directory = $this->absolute($directory);
        $emptyTree = $this->emptyTree($directory);

        return new GitCall(new GitCommand(...$this->command($options, $arguments, $emptyTree)), $directory, $this->environmentOf($this->variables($directory, $emptyTree)));
    }

    /**
     * @param array<string, string|false> $variables
     */
    private function environmentOf(array $variables): GitEnvironment
    {
        $environment = GitEnvironment::of();
        foreach ($variables as $name => $value) {
            $environment = false === $value ? $environment->unsetting($name) : $environment->with(...[$name => $value]);
        }

        return $environment;
    }

    /**
     * @param list<string> $options
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function command(array $options, array $arguments, string $emptyTree): array
    {
        // A refs/replace/ ref would swap in another object, and a lazy fetch would start the transport of a promisor remote the repository names.
        return ['git', '--no-replace-objects', '--no-lazy-fetch', ...$options, '--attr-source='.$emptyTree, ...$this->settings(), ...$arguments];
    }

    /**
     * @return list<string>
     */
    private function settings(): array
    {
        $settings = [];
        foreach (self::SETTINGS as $name => $value) {
            $settings[] = '-c';
            $settings[] = $name.'='.$value;
        }

        return $settings;
    }

    private function absolute(string $directory): string
    {
        // Because the process changes into the directory before git reads GIT_WORK_TREE, a relative one would be resolved twice; a missing one stays as it is, and the call itself fails there.
        $absolute = realpath($directory);

        return false === $absolute ? $directory : $absolute;
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function emptyTree(string $directory): string
    {
        // Because a missing directory holds no repository, and the git call itself fails there.
        if (!is_dir($directory)) {
            return self::EMPTY_TREES['sha1'];
        }

        // Git names the object format and the attributes file, because in a worktree or a submodule the file is not under <directory>/.git; where git cannot, the call itself fails the same way. rev-parse reads no attributes, so SHA-1's empty tree serves before the format is known.
        $query = $this->query($this->question(['--literal-pathspecs'], []), $directory, $directory);
        $answer = explode("\n", trim($query->getOutput()), 2);

        if (!$query->isSuccessful() || 2 !== count($answer)) {
            return self::EMPTY_TREES['sha1'];
        }

        [$format, $path] = $answer;
        $this->refuseInfoAttributes($directory, $path);

        return self::EMPTY_TREES[$format] ?? self::EMPTY_TREES['sha1'];
    }

    /**
     * @param list<string> $options
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function bareEmptyTree(array $options, string $repository): string
    {
        if (!is_dir($repository)) {
            return self::EMPTY_TREES['sha1'];
        }

        // --show-cdup prints a line only where git found a work tree, which under --bare only a core.worktree beside a "core.bare = false" of the repository's own config brings back, since that beats --bare.
        $query = $this->query($this->question($options, ['--show-cdup']), $repository, null);
        $answer = explode("\n", rtrim($query->getOutput(), "\n"), 2);

        if (!$query->isSuccessful() || 2 !== count($answer)) {
            return self::EMPTY_TREES['sha1'];
        }

        [$format, $path] = $answer;

        // Because a line that is empty or holds a slash is a way to a work tree, never an object format; refused as a failed git call, because every caller already handles one.
        if ('' === $format || str_contains($format, '/')) {
            throw WorkTreeInBareRepository::at($repository);
        }

        $this->refuseInfoAttributes($repository, $path);

        return self::EMPTY_TREES[$format] ?? self::EMPTY_TREES['sha1'];
    }

    /**
     * @param list<string> $options
     * @param list<string> $question
     *
     * @return list<string>
     */
    private function question(array $options, array $question): array
    {
        return $this->command($options, ['rev-parse', ...$question, '--show-object-format', '--git-path', self::INFO_ATTRIBUTES], self::EMPTY_TREES['sha1']);
    }

    /**
     * @param list<string> $command
     *
     * @throws RuntimeException
     */
    private function query(array $command, string $directory, ?string $workTree): Process
    {
        $query = new Process($command, $directory, $this->variables($workTree, self::EMPTY_TREES['sha1']));
        $query->run();

        return $query;
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    private function refuseInfoAttributes(string $directory, string $path): void
    {
        $file = str_starts_with($path, '/') ? $path : $directory.'/'.$path;

        if (file_exists($file)) {
            throw RepositoryAttributesRefused::at($file);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function variables(?string $workTree, string $emptyTree): array
    {
        return [...$this->inheritedGitVariables(), ...$this->configuration(), ...$this->isolation($workTree, $emptyTree)];
    }

    /**
     * @return array<string, false>
     */
    private function inheritedGitVariables(): array
    {
        // Inherited GIT_DIR, GIT_INDEX_FILE and the like (as inside a git hook) would point git at another repository.
        $unset = [];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $unset[$name] = false;
            }
        }

        return $unset;
    }

    /**
     * @return array<string, string>
     */
    private function configuration(): array
    {
        // Because tools such as gitleaks start git themselves, which only the environment reaches.
        $environment = ['GIT_CONFIG_COUNT' => (string) count(self::SETTINGS)];
        $index = 0;
        foreach (self::SETTINGS as $name => $value) {
            $environment['GIT_CONFIG_KEY_'.$index] = $name;
            $environment['GIT_CONFIG_VALUE_'.$index] = $value;
            ++$index;
        }

        return $environment;
    }

    /**
     * @return array<string, string|false>
     */
    private function isolation(?string $workTree, string $emptyTree): array
    {
        // The work tree is pinned (a bare call pins the repository as bare instead) because core.worktree in the repository config would point working-tree reads at another directory, info/grafts would rewrite parents as a replace object does, and a partial clone would fetch a missing object through a promisor remote; the empty protocol allowlist beats every protocol.<name>.allow of the repository (protocol.allow=never would not, a more specific setting wins), so no remote helper, ssh command or credential helper it names can run.
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_ALLOW_PROTOCOL' => '',
            'GIT_GRAFT_FILE' => self::NOWHERE,
            'GIT_WORK_TREE' => $workTree ?? false,
            'GIT_ATTR_SOURCE' => $emptyTree,
            'GIT_ATTR_NOSYSTEM' => '1',
            'HOME' => self::NOWHERE,
            'XDG_CONFIG_HOME' => self::NOWHERE,
        ];
    }
}
