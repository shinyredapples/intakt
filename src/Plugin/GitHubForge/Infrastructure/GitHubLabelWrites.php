<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Apart from the comments, because a label is written as the App's installation on the issue's label path.
final readonly class GitHubLabelWrites
{
    private const int NOT_FOUND = 404;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
    ) {
    }

    /**
     * @throws ForgeWriteFailed
     */
    public function remove(string $issuePath, string $label): void
    {
        $options = ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

        try {
            $status = $this->calls->asInstallation('DELETE', $issuePath.'/labels/'.rawurlencode($label), $options)->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        // Tolerated, because a label someone removed meanwhile is the state the removal asks for.
        if (self::NOT_FOUND !== $status && ($status < 200 || $status >= 300)) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status));
        }
    }

    /**
     * @throws ForgeWriteFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub write of a label: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure));
    }
}
