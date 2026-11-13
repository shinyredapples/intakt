<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// GraphQL, because the REST API names neither the account that last edited a body nor the one that applied each label.
final readonly class GitHubIssueHistories
{
    // A history of more label events than this fails, because a label applied later would go unread and its applier with it.
    private const int EVENTS = 100;
    private const int UNAUTHORIZED = 401;
    private const string LABELED = 'LabeledEvent';
    private const string UNLABELED = 'UnlabeledEvent';
    // GraphQL names an App by its bare slug (`renovate`), while the REST API and the trust check name it `renovate[bot]`.
    private const string APP = 'Bot';
    private const string APP_SUFFIX = '[bot]';
    private const string QUERY = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $number: Int!, $events: Int!) {
          repository(owner: $owner, name: $name) {
            issue(number: $number) {
              author { __typename login }
              userContentEdits(first: 1) { nodes { editor { __typename login } } }
              timelineItems(first: $events, itemTypes: [LABELED_EVENT, UNLABELED_EVENT]) {
                pageInfo { hasNextPage }
                nodes {
                  __typename
                  ... on LabeledEvent { actor { __typename login } label { name } }
                  ... on UnlabeledEvent { actor { __typename login } label { name } }
                }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number): IssueHistory
    {
        $issue = $this->issueIn($this->answer($project, $number));

        return new IssueHistory($this->lastEditorOf($issue), $this->labelsOf($issue));
    }

    /**
     * @return array<mixed>
     */
    private function answer(string $project, int $number): array
    {
        $address = $this->repositories->addressOf($project);
        $options = [
            'auth_bearer' => $this->bearer(),
            'headers' => ['Accept' => 'application/vnd.github+json'],
            'json' => ['query' => self::QUERY, 'variables' => ['owner' => $address->owner(), 'name' => $address->name(), 'number' => $number, 'events' => self::EVENTS]],
        ];

        try {
            $response = $this->calls->query($options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($data) ? $data : throw new ForgeReadFailed('GitHub answered JSON that is not an object.');
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    /**
     * @param array<mixed> $answer
     *
     * @return array<mixed>
     */
    private function issueIn(array $answer): array
    {
        // Any error fails the read, also beside partial data, because a field that failed may be the editor and the author must not stand in for it.
        if (array_key_exists('errors', $answer)) {
            throw new ForgeReadFailed('GitHub answered the history read with errors.');
        }

        $issue = $this->at($answer, 'data', 'repository', 'issue');

        return is_array($issue) ? $issue : throw new ForgeReadFailed('GitHub answered a history read without the issue.');
    }

    /**
     * @param array<mixed> $issue
     */
    private function lastEditorOf(array $issue): string
    {
        $edits = $this->at($issue, 'userContentEdits', 'nodes');
        if (!is_array($edits)) {
            throw new ForgeReadFailed('GitHub answered a history read without the body edits.');
        }

        // Without an edit the author is the editor, because GitHub records an edit only after the first write.
        return [] === $edits ? $this->login($issue, 'author') : $this->login(is_array($edits[0] ?? null) ? $edits[0] : [], 'editor');
    }

    /**
     * @param array<mixed> $issue
     *
     * @return list<LabelApplication>
     */
    private function labelsOf(array $issue): array
    {
        $timeline = $this->at($issue, 'timelineItems');
        $events = is_array($timeline) ? ($timeline['nodes'] ?? null) : null;
        // Only a page that says it is the last proves the history whole; a missing flag is no such proof.
        if (!is_array($timeline) || !is_array($events) || false !== $this->at($timeline, 'pageInfo', 'hasNextPage')) {
            throw new ForgeReadFailed('GitHub answered a label history this forge cannot read whole.');
        }

        // Keyed by label, because a label applied again must take its later place and a removed one must leave.
        $applied = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                throw new ForgeReadFailed('GitHub listed a label event that is not an object.');
            }

            // An event of another kind fails instead of counting as a removal, because a removal it did not record would drop a label's applier.
            $kind = $event['__typename'] ?? null;
            if (!in_array($kind, [self::LABELED, self::UNLABELED], true)) {
                throw new ForgeReadFailed('GitHub listed a label event of a kind this forge does not read.');
            }

            // The actor of a removal is checked too, because an answer without one is not the history GitHub recorded.
            $label = $this->text($event, 'label', 'name');
            $actor = $this->login($event, 'actor');
            unset($applied[$label]);
            if (self::LABELED === $kind) {
                $applied[$label] = new LabelApplication($label, $actor);
            }
        }

        return array_values($applied);
    }

    /**
     * @param array<mixed> $data
     */
    private function login(array $data, string $key): string
    {
        // The type is required, because without it an App and a person with the same login could not be told apart.
        $type = $this->text($data, $key, '__typename');
        $login = $this->text($data, $key, 'login');

        return self::APP === $type ? $login.self::APP_SUFFIX : $login;
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data, string ...$keys): string
    {
        $value = $this->at($data, ...$keys);

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
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
