<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Closure;

// Runs another worker's step between this worker's insert and its cleanup, the order two workers recording one window can meet in.
final class OnceFlushed
{
    private bool $ran = false;

    /**
     * @param Closure(): void $step
     */
    public function __construct(
        private readonly Closure $step,
    ) {
    }

    public function postFlush(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;
        ($this->step)();
    }
}
