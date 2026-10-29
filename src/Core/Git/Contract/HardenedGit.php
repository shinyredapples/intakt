<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

final readonly class HardenedGit
{
    // Command-line settings beat the repository's own config; an empty diff.external switches the external diff driver off.
    private const array SETTINGS = [
        'core.hooksPath' => '/dev/null',
        'core.fsmonitor' => 'false',
        'diff.external' => '',
        'core.attributesFile' => '/dev/null',
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
        return ['git', '--no-replace-objects', '--literal-pathspecs', ...$this->settings(), ...$arguments];
    }

    /**
     * For pathspec magic the caller writes itself (a glob pattern of a gate); never for a path from the repository.
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function commandWithPathspecMagic(array $arguments): array
    {
        return ['git', '--no-replace-objects', ...$this->settings(), ...$arguments];
    }

    /**
     * @return array<string, string|false> false removes an inherited variable
     */
    public function environment(): array
    {
        return [...$this->inheritedGitVariables(), ...$this->configuration(), ...$this->isolation()];
    }

    /**
     * @return list<string>
     */
    private function settings(): array
    {
        $settings = [];
        foreach (self::SETTINGS as $name => $value) {
            array_push($settings, '-c', $name.'='.$value);
        }

        return $settings;
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
        // The same settings as command() for tools such as gitleaks that start git themselves.
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
    private function isolation(): array
    {
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'HOME' => self::NO_HOME,
            'XDG_CONFIG_HOME' => self::NO_HOME,
        ];
    }
}
