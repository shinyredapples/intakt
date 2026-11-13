<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use RuntimeException;

final class ComposerFileUnreadable extends RuntimeException
{
    private function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $path, string $reason): self
    {
        return new self($path, sprintf('%s cannot be read: %s', $path, $reason));
    }
}
