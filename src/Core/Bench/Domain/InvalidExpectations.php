<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

final class InvalidExpectations extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('The expectations file is not valid: '.$reason);
    }
}
