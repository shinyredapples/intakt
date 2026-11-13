<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;

final readonly class ComposerPolicy
{
    private SettingChanges $settings;

    private RequirementChanges $requirements;

    private LockedPackageChanges $lockedPackages;

    public function __construct(TrustedPackages $trusted, UpstreamTags $tags)
    {
        $this->settings = new SettingChanges();
        $this->requirements = new RequirementChanges($trusted);
        $this->lockedPackages = new LockedPackageChanges($trusted, $tags);
    }

    public function decide(DependencyFiles $old, DependencyFiles $new): DependencyDecision
    {
        try {
            $before = ComposerState::read($old);
            $after = ComposerState::read($new);
        } catch (ComposerFileUnreadable $unreadable) {
            return DependencyDecision::unreadable($unreadable->path, $unreadable->getMessage());
        }

        return DependencyDecision::of([
            ...$this->settings->between($before, $after),
            ...$this->requirements->between($before, $after),
            ...$this->lockedPackages->between($before, $after),
        ], $after->packagesAddedSince($before));
    }
}
