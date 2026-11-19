<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use InvalidArgumentException;

final class WaitingItemNeedsOneToFourChoices extends InvalidArgumentException
{
    public static function given(int $count): self
    {
        return new self(sprintf('A waiting item has one to four choices, the recommended one first, but %d were given.', $count));
    }
}
