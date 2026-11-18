<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ThingRefused extends RuntimeException implements ReportableFailure
{
}
