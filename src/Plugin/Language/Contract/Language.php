<?php

declare(strict_types=1);

namespace Intakt\Plugin\Language\Contract;

use NoDiscard;

interface Language
{
    // The manifest and lock files the dependency policy reads, from the repository's root, so the caller reads them at each revision it compares.
    /**
     * @return non-empty-list<string>
     */
    public function dependencyFiles(): array;

    #[NoDiscard]
    public function decideDependencies(DependencyFiles $old, DependencyFiles $new): DependencyDecision;
}
