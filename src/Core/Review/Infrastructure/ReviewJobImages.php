<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReviewJobImages
{
    /**
     * @param array<string, string> $images
     */
    public function __construct(
        #[Autowire(param: ReviewServices::JOB_IMAGES)]
        private array $images,
    ) {
    }

    /**
     * @throws ReviewJobFailure
     */
    public function imageFor(string $project): string
    {
        return $this->images[$project] ?? throw ReviewJobFailure::withoutImage($project);
    }
}
