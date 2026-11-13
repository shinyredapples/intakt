<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFinding;

// Transitive packages included, because composer.lock installs them like the ones the manifest names.
final readonly class LockedPackageChanges
{
    private const string COMPOSER_PLUGIN = 'composer-plugin';

    private AutoloadRules $autoload;

    public function __construct(
        private TrustedPackages $trusted,
        private UpstreamTags $tags,
    ) {
        $this->autoload = new AutoloadRules();
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
        $changed = $package->effectsChangedSince($earlier);
        $findings = [] === $changed ? [] : [new DependencyFinding($package->name, sprintf('its %s changed', implode(', ', $changed)))];

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

        $autoload = array_map(static fn (string $reason): DependencyFinding => new DependencyFinding($package->name, $reason), $this->autoload->reasonsAgainst($package));

        return [...$findings, ...$autoload, ...$this->loadingPlaces($package), ...$this->unjudgedKeys($package)];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function loadingPlaces(LockedPackage $package): array
    {
        $findings = [];

        // Prepended to PHP's include path, so every bare require resolves there first.
        if ([] !== $package->includePaths) {
            $findings[] = new DependencyFinding($package->name, 'sets an include path');
        }

        // Appended to the install path, so the package could be written outside its vendor folder; null reads as absent, as Composer's isset() does.
        if (null !== $package->targetDir) {
            $findings[] = new DependencyFinding($package->name, 'sets a target directory');
        }

        return $findings;
    }

    /**
     * @return list<DependencyFinding>
     */
    private function unjudgedKeys(LockedPackage $package): array
    {
        // Extra is allowed because only Composer plugins read it, and the owner allows each plugin.
        $judged = ['type', 'bin', 'autoload', 'include-path', 'target-dir', 'extra'];
        if (LockedPackage::PACKAGIST_NOTIFICATIONS === ($package->effects[LockedPackage::NOTIFICATION_URL] ?? null)) {
            $judged[] = LockedPackage::NOTIFICATION_URL;
        }

        $unjudged = array_diff($package->effectKeys(), $judged);

        return array_map(static fn (string $key): DependencyFinding => new DependencyFinding($package->name, sprintf('has the key "%s", which the policy does not judge', $key)), array_values($unjudged));
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
