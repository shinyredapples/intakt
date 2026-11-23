<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Doctrine\DBAL\Exception;
use RuntimeException;

final class UnreadableLedger extends RuntimeException implements Exception
{
}
