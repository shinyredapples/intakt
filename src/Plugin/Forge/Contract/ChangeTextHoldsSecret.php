<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ChangeTextHoldsSecret extends RuntimeException implements ReportableFailure
{
    public static function inTheText(): self
    {
        return new self('The pull request text trips the secret scan, so no pull request is opened or updated.');
    }
}
