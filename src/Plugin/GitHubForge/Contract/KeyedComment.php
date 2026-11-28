<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The key travels with the text, because it alone tells a later run that this comment is already on the issue: lowercase letters, digits and hyphens, so it fits a hidden marker.
#[Exclude]
final readonly class KeyedComment
{
    public function __construct(
        public string $body,
        public string $key,
    ) {
    }
}
