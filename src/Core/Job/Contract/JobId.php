<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Plain characters only, because the id names the job's container, its output directory and its lock.
final readonly class JobId
{
    private const string PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,62}$/D';

    public function __construct(public string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw JobRefused::forId($value);
        }
    }

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
