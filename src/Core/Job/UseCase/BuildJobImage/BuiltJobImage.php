<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class BuiltJobImage
{
    // The PHP version is null for an image of a project without composer.json, which has no PHP to check.
    public function __construct(
        public JobImagePlan $plan,
        public string $output,
        public ?string $phpVersion,
    ) {
    }
}
