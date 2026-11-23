<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use RuntimeException;
use UnexpectedValueException;

final class PathNotReadable extends RuntimeException
{
    public static function notAFile(string $path): self
    {
        return new self(sprintf('"%s" is not a file.', $path));
    }

    public static function notADirectory(string $path): self
    {
        return new self(sprintf('"%s" is not a directory.', $path));
    }

    public static function notText(string $path): self
    {
        return new self(sprintf('"%s" is not a UTF-8 text file.', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('"%s" cannot be read.', $path));
    }

    public static function unlistable(string $path): self
    {
        return new self(sprintf('"%s" cannot be listed.', $path));
    }

    public static function unsearchable(string $path, UnexpectedValueException $unopened): self
    {
        return new self(sprintf('"%s" cannot be searched.', $path), 0, $unopened);
    }
}
