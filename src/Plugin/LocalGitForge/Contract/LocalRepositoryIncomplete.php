<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;

final class LocalRepositoryIncomplete extends InvalidArgumentException implements ReportableFailure
{
}
