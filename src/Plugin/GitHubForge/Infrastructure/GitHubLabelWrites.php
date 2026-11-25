<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// One home for adding and removing a label, because the status label and an outvoted steering label are written the same way.
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
    public function add(string $issuePath, string $label): void
    {
        $this->write('POST', $issuePath.'/labels', ['labels' => [$label]]);
    }

    /**
     * @throws ForgeWriteFailed
     */
    public function remove(string $issuePath, string $label): bool
    {
        return self::NOT_FOUND !== $this->write('DELETE', $issuePath.'/labels/'.rawurlencode($label), []);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ForgeWriteFailed
     */
    private function write(string $method, string $path, array $body): int
    {
        $options = ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];
        if ([] !== $body) {
            $options['json'] = $body;
        }

        try {
            $status = $this->calls->asInstallation($method, $path, $options)->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        // Tolerated, because a label someone removed meanwhile is the state the removal asks for.
        if (('DELETE' !== $method || self::NOT_FOUND !== $status) && ($status < 200 || $status >= 300)) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status));
        }

        return $status;
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
