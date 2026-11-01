<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity;

use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;

final class RecordedIdentityUnreadable extends InvalidArgumentException implements ReportableFailure
{
}
