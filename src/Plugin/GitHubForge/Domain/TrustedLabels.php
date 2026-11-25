<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Intakt\Core\Review\Contract\Labels;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The outvoted values travel with the winners, because the design removes a family's losing value from the pull request with a comment.
#[Exclude]
final readonly class TrustedLabels
{
    /**
     * @param list<string> $outvoted
     */
    public function __construct(
        public Labels $steering,
        public array $outvoted,
    ) {
    }
}
