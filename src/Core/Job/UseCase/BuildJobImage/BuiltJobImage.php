<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class BuiltJobImage
{
    // No PHP version for a project without composer.json, because it names no PHP to check.
    public function __construct(
        public JobImagePlan $plan,
        public string $output,
        public string $image,
        public ?string $phpVersion,
    ) {
    }
}
