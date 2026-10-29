<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Process;

// The gates keep their own copy of Core/Git/Contract/HardenedGit, because only a protected path may decide how a gate reads the repository.
final readonly class HardenedGitCall
{
    // Because command-line settings beat the repository's own config: an empty diff.external switches the external diff off, a signature check would start the repository's gpg program, and an output encoding would rewrite what git prints.
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
    ];

    // Git's empty tree, so no .gitattributes of the repository applies; a SHA-256 repository refuses it, which fails closed.
    private const string NO_ATTRIBUTES = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    // Because git reads this file on top of every other attributes source and no option, setting or variable switches it off.
    private const string INFO_ATTRIBUTES = 'info/attributes';

    // A path that does not exist, because git reads a missing user config or graft file silently but dies on "/dev/null/.gitconfig".
    private const string NOWHERE = '/nonexistent';

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function command(array $arguments): array
    {
        // A refs/replace/ ref would swap in another object, and pathspec magic would read ":odd.txt" as "odd.txt".
        return ['git', '--no-replace-objects', '--literal-pathspecs', '--attr-source='.self::NO_ATTRIBUTES, ...$this->settings(), ...$arguments];
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function commandWithPathspecMagic(array $arguments): array
    {
        // Because a gate writes glob pathspecs itself, which literal pathspecs refuse; never use it for a path from the repository.
        return ['git', '--no-replace-objects', '--attr-source='.self::NO_ATTRIBUTES, ...$this->settings(), ...$arguments];
    }

    /**
     * @return array<string, string|false>
     *
     * @throws RepositoryAttributesRefused
     */
    public function environment(string $directory): array
    {
        $this->refuseInfoAttributes($directory);

        return $this->variables($directory);
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

    private function refuseInfoAttributes(string $directory): void
    {
        // Because a missing directory holds no repository, and the git call itself fails there.
        if (!is_dir($directory)) {
            return;
        }

        // Git names the file, because in a worktree or a submodule it is not under <directory>/.git; where git cannot, the call itself fails the same way.
        $query = new Process($this->command(['rev-parse', '--git-path', self::INFO_ATTRIBUTES]), $directory, $this->variables($directory));
        $query->run();

        if (!$query->isSuccessful()) {
            return;
        }

        $path = trim($query->getOutput());
        $file = str_starts_with($path, '/') ? $path : $directory.'/'.$path;

        if (file_exists($file)) {
            throw RepositoryAttributesRefused::at($file);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function variables(string $directory): array
    {
        return [...$this->inheritedGitVariables(), ...$this->configuration(), ...$this->isolation($directory)];
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
     * @return array<string, string>
     */
    private function isolation(string $directory): array
    {
        // The work tree is pinned because core.worktree in the repository config would point working-tree reads at another directory, and info/grafts would rewrite parents as a replace object does.
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_GRAFT_FILE' => self::NOWHERE,
            'GIT_WORK_TREE' => $directory,
            'GIT_ATTR_SOURCE' => self::NO_ATTRIBUTES,
            'GIT_ATTR_NOSYSTEM' => '1',
            'HOME' => self::NOWHERE,
            'XDG_CONFIG_HOME' => self::NOWHERE,
        ];
    }
}
