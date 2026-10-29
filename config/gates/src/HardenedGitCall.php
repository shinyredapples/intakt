<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Process;

// The gates keep their own copy of Core/Git/Contract/HardenedGit, because only a protected path may decide how a gate reads the repository.
final readonly class HardenedGitCall
{
    // Because command-line settings beat the repository's own config; an empty diff.external switches the external diff driver off.
    private const array SETTINGS = [
        'core.hooksPath' => '/dev/null',
        'core.fsmonitor' => 'false',
        'diff.external' => '',
        'core.attributesFile' => '/dev/null',
    ];

    // Git's empty tree, so no .gitattributes of the repository applies; a SHA-256 repository refuses it, which fails closed.
    private const string NO_ATTRIBUTES = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    private const string DRIVER_SECTIONS = '^(filter|diff)\.';

    // Because .git/info/attributes still names drivers: an empty filter changes nothing, and cat shows the file as it is.
    private const array DRIVER_OVERRIDES = [
        'clean' => '',
        'smudge' => '',
        'process' => '',
        'required' => 'false',
        'textconv' => 'cat',
        'command' => '',
    ];

    // A path that does not exist, because git reads a missing user config silently but dies on "/dev/null/.gitconfig".
    private const string NO_HOME = '/nonexistent';

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
     */
    public function environment(string $directory): array
    {
        return $this->environmentWith([...self::SETTINGS, ...$this->driverOverrides($directory)]);
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

    /**
     * @return array<string, string>
     */
    private function driverOverrides(string $directory): array
    {
        // Because a missing directory holds no repository config, and the git call itself fails there.
        if (!is_dir($directory)) {
            return [];
        }

        $query = new Process($this->command(['config', '--null', '--name-only', '--get-regexp', self::DRIVER_SECTIONS]), $directory, $this->environmentWith(self::SETTINGS));
        $query->run();

        $overrides = [];
        foreach (explode("\0", $query->getOutput()) as $name) {
            $override = self::DRIVER_OVERRIDES[substr((string) strrchr($name, '.'), 1)] ?? null;

            if (null !== $override) {
                $overrides[$name] = $override;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, string> $settings
     *
     * @return array<string, string|false>
     */
    private function environmentWith(array $settings): array
    {
        return [...$this->inheritedGitVariables(), ...$this->configuration($settings), ...$this->isolation()];
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
     * @param array<string, string> $settings
     *
     * @return array<string, string>
     */
    private function configuration(array $settings): array
    {
        // Because tools such as gitleaks start git themselves; the variables also carry driver names that a -c option would split wrongly.
        $environment = ['GIT_CONFIG_COUNT' => (string) count($settings)];
        $index = 0;
        foreach ($settings as $name => $value) {
            $environment['GIT_CONFIG_KEY_'.$index] = $name;
            $environment['GIT_CONFIG_VALUE_'.$index] = $value;
            ++$index;
        }

        return $environment;
    }

    /**
     * @return array<string, string>
     */
    private function isolation(): array
    {
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_ATTR_SOURCE' => self::NO_ATTRIBUTES,
            'HOME' => self::NO_HOME,
            'XDG_CONFIG_HOME' => self::NO_HOME,
        ];
    }
}
