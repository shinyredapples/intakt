<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImageBuild
{
    // The engine's own error line, so a failed step is told apart without reading the output; the image id, because a tag can be moved by another build before this one is checked.
    public function __construct(
        public string $output,
        public ?string $error,
        public ?string $image,
    ) {
    }

    public function failed(): bool
    {
        return null !== $this->error;
    }
}
