<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobImages;
use Override;

final readonly class EngineJobImages implements JobImages
{
    public function __construct(
        private DockerEngine $engine,
    ) {
    }

    #[Override]
    public function idOf(string $image): string
    {
        return $this->engine->imageId($image);
    }
}
