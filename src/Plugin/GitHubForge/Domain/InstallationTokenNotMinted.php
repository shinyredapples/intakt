<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use RuntimeException;
use Throwable;

// The message never holds the key, the signed JWT or a token: it is logged and shown.
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
}
