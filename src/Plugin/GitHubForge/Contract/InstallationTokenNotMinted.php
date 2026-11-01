<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use RuntimeException;
use Throwable;

// Messages carry the status or the exception class only, because they are logged and shown and must not leak the key or a token.
final class InstallationTokenNotMinted extends RuntimeException
{
    public static function becauseTheKeyCannotSign(): self
    {
        return new self('The GitHub App private key cannot be read or cannot sign.');
    }

    public static function becauseGitHubRefused(int $status): self
    {
        return new self(sprintf('GitHub refused the installation token exchange with status %d.', $status));
    }

    public static function becauseGitHubWasUnreachable(Throwable $transport): self
    {
        return new self('The installation token exchange did not complete: '.$transport::class.'.', previous: $transport);
    }

    public static function becauseTheAnswerIsInvalid(): self
    {
        return new self('GitHub answered the installation token exchange without a usable token and expiry.');
    }

    public static function becauseTheTokenLapsesTooSoon(): self
    {
        return new self('GitHub answered the installation token exchange with a token that has expired or expires within a minute.');
    }
}
