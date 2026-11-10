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

    public static function unlisted(): self
    {
        return new self('The job output directory could not be listed.');
    }
}
