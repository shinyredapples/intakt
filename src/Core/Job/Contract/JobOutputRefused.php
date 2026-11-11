<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use RuntimeException;

final class JobOutputRefused extends RuntimeException
{
    public static function notAPlainFile(string $name): self
    {
        return new self(sprintf('The job output "%s" is not a plain file; links and folders are refused.', $name));
    }

    public static function unreadable(string $name): self
    {
        return new self(sprintf('The job output "%s" could not be read.', $name));
    }

    public static function tooLarge(string $name, int $limit): self
    {
        return new self(sprintf('The job output exceeds %d bytes in total at "%s"; it is refused unread.', $limit, $name));
    }

    public static function tooMany(int $limit): self
    {
        return new self(sprintf('The job output has more than %d entries; it is refused unread.', $limit));
    }

    public static function unlisted(): self
    {
        return new self('The job output directory could not be listed.');
    }
}
