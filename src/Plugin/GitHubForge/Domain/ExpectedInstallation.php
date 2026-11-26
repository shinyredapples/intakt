<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class ExpectedInstallation
{
    /**
     * @param array<string, string> $permissions
     * @param list<string>          $repositories
     */
    public function __construct(
        public array $permissions,
        public array $repositories,
    ) {
    }

    // Settings are env-shaped strings, because the container hands them over as such; they are "name:level,name:level" and "owner/repo,owner/repo", parsed here once.
    public static function fromSettings(string $permissions, string $repositories): self
    {
        $levels = [];
        foreach (self::items($permissions) as $item) {
            [$name, $level] = [...explode(':', $item, 2), ''];
            if ('' === $name || '' === $level) {
                throw new InstallationSettingsRefused('GITHUB_APP_PERMISSIONS must list name:level pairs, such as contents:write.');
            }
            // The comparison is exact, so a second level for one name would silently replace the first.
            if (array_key_exists($name, $levels)) {
                throw new InstallationSettingsRefused(sprintf('GITHUB_APP_PERMISSIONS names %s more than once.', $name));
            }
            $levels[$name] = $level;
        }

        $names = self::items($repositories);
        if ([] === $levels || [] === $names) {
            throw new InstallationSettingsRefused('GITHUB_APP_PERMISSIONS and GITHUB_APP_REPOSITORIES must each name at least one entry.');
        }

        return new self($levels, $names);
    }

    /**
     * @return list<string>
     */
    public function differencesFrom(Installation $installation): array
    {
        return [...$this->permissionDifferences($installation), ...$this->repositoryDifferences($installation)];
    }

    /**
     * @return list<string>
     */
    private function permissionDifferences(Installation $installation): array
    {
        $differences = [];
        foreach ($this->permissions as $name => $level) {
            $granted = $installation->permissions[$name] ?? null;
            if (null === $granted) {
                $differences[] = sprintf('permission %s is expected at %s but not granted', $name, $level);
            } elseif ($granted !== $level) {
                $differences[] = sprintf('permission %s is expected at %s but granted at %s', $name, $level, $granted);
            }
        }
        foreach (array_diff_key($installation->permissions, $this->permissions) as $name => $granted) {
            $differences[] = sprintf('permission %s is granted at %s but not expected', $name, $granted);
        }

        return $differences;
    }

    /**
     * @return list<string>
     */
    private function repositoryDifferences(Installation $installation): array
    {
        $differences = [];
        foreach (array_diff($this->repositories, $installation->repositories) as $missing) {
            $differences[] = sprintf('repository %s is expected but not in the installation', $missing);
        }
        foreach (array_diff($installation->repositories, $this->repositories) as $extra) {
            $differences[] = sprintf('repository %s is in the installation but not expected', $extra);
        }

        return $differences;
    }

    /**
     * @return list<string>
     */
    private static function items(string $setting): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $setting)), static fn (string $item): bool => '' !== $item));
    }
}
