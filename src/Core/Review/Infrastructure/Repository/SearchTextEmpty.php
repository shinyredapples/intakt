<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use InvalidArgumentException;

final class SearchTextEmpty extends InvalidArgumentException
{
    public static function create(): self
    {
        return new self('The text to find is empty.');
    }
}
