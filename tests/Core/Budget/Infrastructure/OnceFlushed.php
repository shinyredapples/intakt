<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Closure;

// A race is proven by a forced order, never by repetition, so another worker's step runs between this worker's insert and its cleanup.
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
