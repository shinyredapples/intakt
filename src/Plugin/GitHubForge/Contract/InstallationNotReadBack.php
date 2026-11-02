<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Messages carry the status or the exception class only, because they are logged and shown and must not leak the key or a token.
final class InstallationNotReadBack extends RuntimeException implements ReportableFailure
{
    public static function becauseNoTokenWasMinted(string $reason): self
    {
        return new self('The installation cannot be read back: '.$reason);
    }

    public static function becauseGitHubRefused(int $status): self
    {
        return new self(sprintf('GitHub refused to read the installation back with status %d.', $status));
    }

    public static function becauseGitHubWasUnreachable(string $transportClass): self
    {
        return new self('The installation read back did not complete: '.$transportClass.'.');
    }

    public static function becauseTheListingIsIncomplete(int $read, int $total): self
    {
        return new self(sprintf('GitHub listed %d repositories of the installation while it counts %d.', $read, $total));
    }

    public static function becauseTheAnswerIsInvalid(): self
    {
        return new self('GitHub answered the installation read back with permissions or repositories in an unusable shape.');
    }
}
