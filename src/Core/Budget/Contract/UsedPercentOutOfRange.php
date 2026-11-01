<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use InvalidArgumentException;

// A vendor's answer is untrusted input, so a used share that is negative or not a number fails loudly instead of reading as an empty window.
final class UsedPercentOutOfRange extends InvalidArgumentException
{
    public static function of(float $usedPercent): self
    {
        return new self(sprintf('A measured use of %s percent is not a reading.', var_export($usedPercent, true)));
    }
}
