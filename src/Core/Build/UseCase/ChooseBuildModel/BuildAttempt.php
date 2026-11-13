<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use InvalidArgumentException;

final readonly class BuildAttempt
{
    /**
     * @param positive-int $number
     */
    public function __construct(
        public string $project,
        public string $task,
        public int $number,
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException('An attempt number starts at 1.');
        }
    }
}
