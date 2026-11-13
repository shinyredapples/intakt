<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// A port, because the answer comes from the network, which no test may reach.
interface UpstreamTags
{
    // The commit the tag names in the repository, or null when the repository has no such tag.
    /**
     * @throws UpstreamTagNotRead
     */
    public function commitOf(string $repositoryUrl, string $tag): ?string;
}
