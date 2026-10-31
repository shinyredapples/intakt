<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use LogicException;

final class RoundNotAfterLatest extends LogicException
{
    public static function for(int $round, int $latest): self
    {
        return new self('Round '.$round.' is not after the latest recorded round '.$latest.'.');
    }
}
