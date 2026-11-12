<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// One home for the reads of the issue listing and the item histories, because both read with the confirmed installation's token and refuse the same answers.
final readonly class InstallationReads
{
    public const int NOT_MODIFIED = 304;
    private const int UNAUTHORIZED = 401;
    private const string NEXT_LINK = '/<[^>]*>\s*;\s*rel="?next"?/';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, etag: ?string, next: bool}
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function send(string $method, string $path, array $options): array
    {
        $options['auth_bearer'] = $this->bearer();
        $options['headers'] = ['Accept' => 'application/vnd.github+json', ...(is_array($options['headers'] ?? null) ? $options['headers'] : [])];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if (self::NOT_MODIFIED !== $status && ($status < 200 || $status >= 300)) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['status' => $status, 'body' => $body, 'etag' => $headers['etag'][0] ?? null, 'next' => 1 === preg_match(self::NEXT_LINK, implode(',', $headers['link'] ?? []))];
    }

    /**
     * @return array<mixed>
     */
    public function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($data) ? $data : throw new ForgeReadFailed('GitHub answered JSON that is not an object or a list.');
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a read.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
