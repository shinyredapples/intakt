<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;

final class ChangeIdEmpty extends InvalidArgumentException implements ReportableFailure
{
}
