<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// The message names permissions and repositories only, because it is printed and logged.
final class InstallationDoesNotMatchConfiguration extends RuntimeException implements ReportableFailure
{
    /**
     * @param list<string> $differences
     */
    public static function with(array $differences): self
    {
        return new self('The GitHub App installation does not match the configuration: '.implode('; ', $differences).'.');
    }
}
