<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Doctrine\DBAL\Driver\AbstractException;

// A driver's own failure, because DBAL wraps one in every exception it raises and its PDO one is internal.
final class DriverFailure extends AbstractException
{
}
