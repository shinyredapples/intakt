<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built per read, so the container has no reason to hold it; the next-page flag is the Link header's word, kept beside the body it came with.
#[Exclude]
final readonly class ReadAnswer
{
    public function __construct(
        public string $body,
        public bool $hasNextPage,
    ) {
    }
}
