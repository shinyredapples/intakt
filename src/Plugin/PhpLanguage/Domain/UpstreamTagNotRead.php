<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use RuntimeException;

final class UpstreamTagNotRead extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('the upstream tag could not be read: '.$reason);
    }
}
