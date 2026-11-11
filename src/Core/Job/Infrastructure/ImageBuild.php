<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImageBuild
{
    // The error is the engine's message for a step that failed, null when the image was built.
    public function __construct(
        public string $output,
        public ?string $error,
    ) {
    }

    public function failed(): bool
    {
        return null !== $this->error;
    }
}
