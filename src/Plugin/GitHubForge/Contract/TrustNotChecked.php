<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Messages carry names, statuses or exception classes only, because they are logged and shown and must not leak the key or a token.
final class TrustNotChecked extends RuntimeException implements ReportableFailure
{
    public static function becauseTheAccountIsMalformed(): self
    {
        return new self('The account is not a GitHub login.');
    }

    public static function becauseTheRepositoryIsMalformed(): self
    {
        return new self('The repository is not an owner/name pair.');
    }

    public static function becauseNoCredential(string $reason): self
    {
        return new self('Write access cannot be checked without the App identity: '.$reason);
    }

    public static function becauseGitHubRefused(int $status): self
    {
        return new self(sprintf('GitHub refused the trust check with status %d.', $status));
    }

    // Takes the class only, because the transport exception's trace holds the request options with the credential.
    public static function becauseGitHubWasUnreachable(string $transportClass): self
    {
        return new self('The trust check did not complete: '.$transportClass.'.');
    }

    public static function becauseTheAnswerIsInvalid(): self
    {
        return new self('GitHub answered the trust check in an unusable shape.');
    }
}
