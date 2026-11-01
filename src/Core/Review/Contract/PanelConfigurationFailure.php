<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

abstract class PanelConfigurationFailure extends RuntimeException implements ReportableFailure
{
}
