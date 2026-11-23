<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The rows of one complete listing belong with the validator it was answered with, because the next listing is asked with that validator against these rows.
#[Exclude]
final readonly class ListingRows
{
    /**
     * @param list<StoredIssue> $issues
     */
    public function __construct(
        public array $issues,
        public ?string $validator,
    ) {
    }
}
