<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use RuntimeException;

final class StoredMemoryIsMalformed extends RuntimeException
{
    public function __construct(int|string $documentId, string $key)
    {
        parent::__construct(sprintf('Stored memory document "%s" has a missing or malformed "%s".', $documentId, $key));
    }
}
