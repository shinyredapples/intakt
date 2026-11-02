<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use NoDiscard;

// The forge's only token door, so no call reaches GitHub before the installation was read back and matched.
final class CheckedInstallationTokens
{
    // Kept for the whole process and not reset between messages, because the check guards the start, not each call.
    private bool $checked = false;

    public function __construct(
        private readonly InstallationReadBack $readBack,
        private readonly InstallationTokens $tokens,
    ) {
    }

    /**
     * @throws InstallationNotReadBack
     * @throws InstallationDoesNotMatchConfiguration
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('the token is the credential of the call')]
    public function current(): InstallationToken
    {
        if (!$this->checked) {
            (void) $this->readBack->check();
            $this->checked = true;
        }

        return $this->tokens->current();
    }
}
