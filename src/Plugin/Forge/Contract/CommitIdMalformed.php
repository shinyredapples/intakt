<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

// Not reportable, because a forge answer or a stored identity reaches it too; a door that reads a typed id reports it itself.
final class CommitIdMalformed extends InvalidArgumentException
{
}
