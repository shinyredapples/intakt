<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFinding;

// Transitive packages included, because composer.lock installs them like the ones the manifest names.
final readonly class LockedPackageChanges
{
    private const string COMPOSER_PLUGIN = 'composer-plugin';

    // A root, a drive or a stream wrapper.
    private const string ABSOLUTE = '~\A(?:[/\\\\]|[A-Za-z]:|[A-Za-z][A-Za-z0-9+.-]*://)~';

    public function __construct(
        private TrustedPackages $trusted,
        private UpstreamTags $tags,
    ) {
    }

    /**
     * @return list<DependencyFinding>
     */
    public function between(ComposerState $before, ComposerState $after): array
    {
        $findings = [];
        foreach (array_diff_key($before->locked, $after->locked) as $removed) {
            $findings[] = new DependencyFinding($removed->name, 'was removed from composer.lock');
        }

        foreach ($after->locked as $name => $package) {
            $earlier = $before->locked[$name] ?? null;
            $findings = [...$findings, ...match (true) {
                null === $earlier => $this->added($package),
                $earlier->sameReleaseAs($package) => [],
                default => $this->updated($earlier, $package),
            }, ...$this->moved($earlier, $package)];
        }

        return $findings;
    }

    /**
     * @return list<DependencyFinding>
     */
    private function added(LockedPackage $package): array
    {
        // Never looked up upstream, because the owner decides it anyway and its repository could be any address.
        if (!$this->trusted->trusts($package->name)) {
            return [new DependencyFinding($package->name, 'is not from a trusted vendor')];
        }

        $findings = $this->effects($package);

        // Its own vendor's organization, because GitHub serves a commit pushed to any fork under the upstream repository's address too, and the tag check below rules that out.
        if (!$package->commit instanceof GitHubCommit || strtolower($package->commit->owner) !== $package->vendor()) {
            return [...$findings, new DependencyFinding($package->name, 'does not come from its own vendor\'s repository at one commit')];
        }

        return [...$findings, ...$this->atUpstreamTag($package, $package->commit)];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function updated(LockedPackage $earlier, LockedPackage $package): array
    {
        $findings = $earlier->sameEffectsAs($package) ? [] : [new DependencyFinding($package->name, 'its type, binaries or autoload changed')];

        if (!$package->commit instanceof GitHubCommit || !$earlier->commit instanceof GitHubCommit || !$earlier->commit->sameRepositoryAs($package->commit)) {
            return [...$findings, new DependencyFinding($package->name, 'its repository changed or cannot be checked')];
        }

        return [...$findings, ...$this->atUpstreamTag($package, $package->commit)];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function moved(?LockedPackage $earlier, LockedPackage $package): array
    {
        if (!$earlier instanceof LockedPackage || $earlier->group === $package->group || $this->trusted->trusts($package->name)) {
            return [];
        }

        return [new DependencyFinding($package->name, 'moved between the main and the development packages')];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function effects(LockedPackage $package): array
    {
        $findings = [];

        if (self::COMPOSER_PLUGIN === $package->type) {
            $findings[] = new DependencyFinding($package->name, 'is a Composer plugin');
        } elseif (!$this->trusted->allowsType($package->type)) {
            $findings[] = new DependencyFinding($package->name, sprintf('has the package type "%s", which is not allowed', $package->type));
        }

        if ([] !== $package->binaries) {
            $findings[] = new DependencyFinding($package->name, 'ships binaries');
        }

        return [...$findings, ...$this->autoload($package)];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function autoload(LockedPackage $package): array
    {
        if (!is_array($package->autoload)) {
            return [new DependencyFinding($package->name, 'its autoload cannot be checked')];
        }

        $reasons = [];
        foreach ($package->autoload as $kind => $rules) {
            $reasons[] = match ($kind) {
                // It only leaves files out.
                'exclude-from-classmap' => null,
                'files' => [] === $rules ? null : 'has autoload files',
                'classmap' => [] === $rules ? null : 'has an autoload classmap',
                'psr-4', 'psr-0' => $this->ownsEveryMapping($package, $rules) ? null : 'maps autoload outside its own namespace or folder',
                default => 'its autoload cannot be checked',
            };
        }

        return array_map(static fn (string $reason): DependencyFinding => new DependencyFinding($package->name, $reason), array_values(array_unique(array_filter($reasons, is_string(...)))));
    }

    private function ownsEveryMapping(LockedPackage $package, mixed $mappings): bool
    {
        if (!is_array($mappings)) {
            return false;
        }

        foreach ($mappings as $namespace => $folders) {
            $folders = is_array($folders) ? $folders : [$folders];
            if (!$package->ownsNamespace((string) $namespace) || [] === $folders || [] !== array_filter($folders, static fn (mixed $folder): bool => !self::insidePackage($folder))) {
                return false;
            }
        }

        return true;
    }

    // Relative and without a step up, because the autoloader would otherwise load files from the project or another package.
    private static function insidePackage(mixed $folder): bool
    {
        if (!is_string($folder) || 1 === preg_match(self::ABSOLUTE, $folder)) {
            return false;
        }

        return !in_array('..', preg_split('~[/\\\\]~', $folder) ?: [], true);
    }

    /**
     * @return list<DependencyFinding>
     */
    private function atUpstreamTag(LockedPackage $package, GitHubCommit $commit): array
    {
        if (!$package->isTagged()) {
            return [new DependencyFinding($package->name, sprintf('is locked at %s, which is no release tag', $package->version))];
        }

        try {
            $tagged = $this->tags->commitOf($commit->cloneUrl(), $package->version);
        } catch (UpstreamTagNotRead $unread) {
            return [new DependencyFinding($package->name, $unread->getMessage())];
        }

        if ($tagged !== $commit->reference) {
            return [new DependencyFinding($package->name, sprintf('commit %s is not the upstream tag %s', $commit->reference, $package->version))];
        }

        return [];
    }
}
