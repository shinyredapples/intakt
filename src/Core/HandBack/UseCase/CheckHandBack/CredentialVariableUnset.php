<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class CredentialVariableUnset extends RuntimeException implements ReportableFailure
{
    public static function named(string $variable): self
    {
        return new self(sprintf('the credential variable "%s" is unset or empty', $variable));
    }
}
