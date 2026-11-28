<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// The REST calls that file a split part, apart from the filing itself, so the filing reads as its order of reads and writes.
final readonly class PartIssueCalls
{
    private const int UNAUTHORIZED = 401;
    private const int FIRST_REFUSAL = 400;
    private const int LAST_REFUSAL = 499;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private NextLinks $nextLinks,
    ) {
    }

    /**
     * @param array<string, int|string> $query
     *
     * @throws ForgeReadFailed
     */
    public function read(string $path, array $query): IssueRead
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $links = $response->getHeaders(false)['link'] ?? [];
            $answered = $response->getInfo('url');
            $target = is_string($answered) ? $answered : $path;
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of %s.', $status, $path), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return new IssueRead($body, $this->nextLinks->namesNext($links, $target));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function written(string $method, string $path, array $payload): array
    {
        try {
            return $this->sent($method, $path, $payload);
        } catch (WriteRefused $refused) {
            throw $refused->failure;
        }
    }

    // Apart from the other writes, because a create GitHub certainly did not store may be sent again, and its caller records which it was.
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws WriteRefused
     * @throws ForgeWriteFailed
     */
    public function created(string $path, array $payload): array
    {
        return $this->sent('POST', $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws WriteRefused
     * @throws ForgeWriteFailed
     */
    private function sent(string $method, string $path, array $payload): array
    {
        // Never retried, because GitHub can create an issue and still answer 502, and a retry would file it twice.
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => $payload, 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
        } catch (ForgeRateLimited $limited) {
            // A refusal, because the pause is raised before anything is sent or for an answer of 403 or 429, and GitHub stored nothing either way.
            throw new WriteRefused($limited);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachableForWrite($failure::class);
        }

        // Classified from the status alone, before the body is read, because a 4xx answer whose body is lost still says GitHub stored nothing.
        if ($status < 200 || $status >= 300) {
            throw $this->failedWith($status);
        }

        try {
            $stored = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachableForWrite($failure::class);
        }

        return $this->answeredIssue($stored);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the issues: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    // A refusal, because without a token nothing is sent.
    /**
     * @throws WriteRefused
     */
    private function writeBearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new WriteRefused(new ForgeWriteFailed('No GitHub write of an issue: '.$refused->getMessage(), $refused->getCode(), previous: $refused));
        }
    }

    // Not chained either, because the trace of a failed create holds the bearer token too.
    private function unreachableForWrite(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure));
    }

    // A refusal only for a 4xx answer, because it alone says GitHub did not act on the request: after any other answer the write may have been stored.
    private function failedWith(int $status): ForgeWriteFailed|WriteRefused
    {
        $failed = new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);

        return $status >= self::FIRST_REFUSAL && $status <= self::LAST_REFUSAL ? new WriteRefused($failed) : $failed;
    }

    /**
     * @return array<mixed>
     *
     * @throws ForgeWriteFailed
     */
    private function answeredIssue(string $answer): array
    {
        try {
            $issue = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub answered the write with something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        // `[]` decodes to the same empty array as `{}`, so only the answer's first character tells an issue from a list.
        return is_array($issue) && str_starts_with(ltrim($answer), '{') ? $issue : throw new ForgeWriteFailed('GitHub answered the write with something other than an issue.');
    }
}
