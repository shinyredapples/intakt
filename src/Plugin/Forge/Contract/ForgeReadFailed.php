<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ForgeReadFailed extends RuntimeException implements ReportableFailure
{
}
