<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Tier: string
{
    case Strong = 'strong';
    case Standard = 'standard';
    case Light = 'light';

    public const string LABEL_PREFIX = 'model:';

    // Ignored, never guessed at, because a value that names no tier (a model, a vendor) must not pick one.
    public static function fromLabel(string $label): ?self
    {
        return str_starts_with($label, self::LABEL_PREFIX) ? self::tryFrom(substr($label, strlen(self::LABEL_PREFIX))) : null;
    }

    // Because a full verdict with its findings must fit, and the strong tier writes the longest reviews.
    /**
     * @return positive-int
     */
    public function defaultMaxOutput(): int
    {
        return match ($this) {
            self::Strong => 16000,
            self::Standard => 8000,
            self::Light => 4000,
        };
    }
}
