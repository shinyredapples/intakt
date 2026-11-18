<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

interface JobImages
{
    /**
     * The engine's content-addressed ID, which a rebuilt tag does not carry over, so a caller can pin two jobs and a cache key to one image.
     *
     * @throws DockerEngineFailed
     */
    public function idOf(string $image): string;
}
