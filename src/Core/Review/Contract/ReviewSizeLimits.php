<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReviewSizeLimits
{
    /**
     * @param array<string, int> $characters
     */
    public function __construct(
        #[Autowire(param: ReviewServices::SIZE_LIMITS)]
        private array $characters,
    ) {
    }

    public function for(string $project): ReviewSizeLimit
    {
        return isset($this->characters[$project]) ? new ReviewSizeLimit($this->characters[$project]) : ReviewSizeLimit::default();
    }
}
