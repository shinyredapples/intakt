<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use LogicException;

final class MixedFigureKinds extends LogicException
{
    public static function inTotal(): self
    {
        return new self('A total over figures of different kinds is not shown: measured, counted and estimated figures stand side by side.');
    }
}
