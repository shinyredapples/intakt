<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use InvalidArgumentException;

final readonly class BuildAttempt
{
    public function __construct(
        public string $project,
        public string $task,
        public int $number,
    ) {
        $refusal = self::refusalOf($project, $task, $number);

        if (null !== $refusal) {
            throw new InvalidArgumentException($refusal);
        }
    }

    // the model_chosen payload is JSON, which cannot hold a value that is not UTF-8
    public static function refusalOf(string $project, string $task, int $number): ?string
    {
        return match (true) {
            '' === $project => 'A project must not be empty.',
            '' === $task => 'A task must not be empty.',
            !mb_check_encoding($project, 'UTF-8') => 'A project must be valid UTF-8.',
            !mb_check_encoding($task, 'UTF-8') => 'A task must be valid UTF-8.',
            $number < 1 => 'An attempt number starts at 1.',
            default => null,
        };
    }
}
