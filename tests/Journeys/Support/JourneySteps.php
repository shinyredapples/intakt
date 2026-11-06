<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\AssertionFailedError;
use Throwable;

final readonly class JourneySteps
{
    public function __construct(private string $journey)
    {
    }

    /**
     * @template Result
     *
     * @param callable(): Result $action
     *
     * @return Result
     */
    public function run(string $step, callable $action): mixed
    {
        try {
            return $action();
        } catch (Throwable $failure) {
            // One message shape, so the gate and a reader find the journey and the step without reading a trace.
            throw new AssertionFailedError(sprintf('journey "%s" failed at step "%s": %s', $this->journey, $step, $failure->getMessage()), 0, $failure);
        }
    }
}
