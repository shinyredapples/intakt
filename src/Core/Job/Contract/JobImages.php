<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

interface JobImages
{
    // The engine's content-addressed ID, because a rebuilt tag would let two jobs or a cache key name different images.
    /**
     * @throws DockerEngineFailed
     */
    public function idOf(string $image): string;
}
