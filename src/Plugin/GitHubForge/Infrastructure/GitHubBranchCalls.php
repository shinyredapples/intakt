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

// The reads and the delete of branch cleanup, each answer checked for its shape, because a branch read wrongly could be deleted.
final readonly class GitHubBranchCalls
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because a weekly run must end.
    private const int MAX_PAGES = 10;
    private const int OK = 200;
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private NextLinks $nextLinks,
    ) {
    }

    /**
     * @return array<mixed>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function object(string $path): array
    {
        $body = $this->exchange($path, [])['body'];
        $data = $this->decode($body);

        return !array_is_list($data) || [] === $data ? $data : throw new ForgeReadFailed('GitHub answered a list where an object was expected.');
    }

    // Every page, because a pull request or commit on a later page may be the open or kept one.
    /**
     * @param array<string, int|string> $query
     *
     * @return list<mixed>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function pages(string $path, array $query, ?string $key = null): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $query = [...$query, 'per_page' => self::PAGE_SIZE, 'page' => $page];
            $answer = $this->exchange($path, $query);
            $items = [...$items, ...$this->itemsOf($answer['body'], $key)];
            if (!$this->nextLinks->namesNext($answer['headers']['link'] ?? [], $path.'?'.http_build_query($query, '', '&'))) {
                return $items;
            }
        }

        throw new ForgeReadFailed('GitHub lists more pages than this forge reads.');
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ForgeReadFailed
     */
    public function text(array $data, string ...$keys): string
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function delete(string $path): int
    {
        try {
            return $this->calls->asInstallation('DELETE', $path, ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json']])->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw new ForgeWriteFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        } catch (ForgeReadFailed $noToken) {
            throw new ForgeWriteFailed($noToken->getMessage(), $noToken->getCode(), $noToken);
        }
    }

    /**
     * @return list<mixed>
     */
    private function itemsOf(string $body, ?string $key): array
    {
        $data = $this->decode($body);
        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        $items = null === $key && str_starts_with(ltrim($body), '[') ? $data : ($data[$key ?? ''] ?? null);

        return is_array($items) && array_is_list($items) ? $items : throw new ForgeReadFailed('GitHub answered a listing that is not a list.');
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array{body: string, headers: array<string, list<string>>}
     */
    private function exchange(string $path, array $query): array
    {
        try {
            $response = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        }

        if (self::OK !== $status) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['body' => $body, 'headers' => $headers];
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($data) ? $data : throw new ForgeReadFailed('GitHub answered JSON that is not an object or a list.');
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a branch call.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub call before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
