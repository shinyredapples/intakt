<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// GraphQL, because the REST API names neither the account that last edited a body nor the one that applied each label.
final readonly class GitHubIssueHistories implements IssueHistories
{
    // A history of more label events than this fails, because a label applied later would go unread and its applier with it.
    private const int EVENTS = 100;
    private const int UNAUTHORIZED = 401;
    private const string LABELED = 'LabeledEvent';
    private const string UNLABELED = 'UnlabeledEvent';
    // Suffixed, because GraphQL names an App by its bare slug (`renovate`) while the trust check tells an App from a person by the `[bot]` the REST API adds.
    private const string APP = 'Bot';
    private const string APP_SUFFIX = '[bot]';
    // Issue or pull request, because the listing holds both and a pull request's closing keyword steers only by its history; the update time, the body and the labels are read beside the history, because only a history of the listed text and labels vouches for them and the update time alone has whole seconds.
    private const string QUERY = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $number: Int!, $events: Int!) {
          repository(owner: $owner, name: $name) {
            issueOrPullRequest(number: $number) {
              __typename
              ... on Comment {
                updatedAt
                body
                author { __typename login }
                userContentEdits(first: 1) { nodes { editor { __typename login } } }
              }
              ... on Labelable {
                labels(first: $events) { pageInfo { hasNextPage } nodes { name } }
              }
              ... on Issue {
                timelineItems(first: $events, itemTypes: [LABELED_EVENT, UNLABELED_EVENT]) {
                  pageInfo { hasNextPage }
                  nodes { __typename ...labelEvent ...unlabelEvent }
                }
              }
              ... on PullRequest {
                timelineItems(first: $events, itemTypes: [LABELED_EVENT, UNLABELED_EVENT]) {
                  pageInfo { hasNextPage }
                  nodes { __typename ...labelEvent ...unlabelEvent }
                }
              }
            }
          }
        }

        fragment labelEvent on LabeledEvent { actor { __typename login } label { name } }

        fragment unlabelEvent on UnlabeledEvent { actor { __typename login } label { name } }
        GRAPHQL;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private GitHubMoments $moments,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        $issue = $this->issueIn($this->answer($project, $number));
        // A later update fails the read instead of vouching, because an edit or a label made after the listing would vouch for text and labels the listing does not hold.
        if ($this->moments->parse($this->text($issue, 'updatedAt'))->getTimestamp() !== $listedAt->getTimestamp()) {
            throw new ForgeReadFailed(sprintf('GitHub changed #%d after it was listed, so its history may be of other text and labels.', $number));
        }

        return new IssueHistory($this->lastEditorOf($issue), $this->labelsOf($issue), $this->bodyOf($issue), $this->currentLabelsOf($issue));
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

        $issue = $this->at($answer, 'data', 'repository', 'issueOrPullRequest');

        return is_array($issue) ? $issue : throw new ForgeReadFailed('GitHub answered a history read without the issue or pull request.');
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
        // Keyed by label, because a label applied again must take its later place and a removed one must leave.
        $applied = [];
        foreach ($this->eventsOf($issue) as $event) {
            $kind = $this->kindOf($event);
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
     * @param array<mixed> $issue
     */
    private function bodyOf(array $issue): string
    {
        $body = $issue['body'] ?? null;

        // Empty is allowed, because an item without a description has the empty body.
        return is_string($body) ? $body : throw new ForgeReadFailed('GitHub answered a history read without the body.');
    }

    /**
     * @param array<mixed> $issue
     */
    private function currentLabelsOf(array $issue): IssueLabels
    {
        $labels = $this->at($issue, 'labels');
        $nodes = is_array($labels) ? ($labels['nodes'] ?? null) : null;
        if (!is_array($labels) || !is_array($nodes) || false !== $this->at($labels, 'pageInfo', 'hasNextPage')) {
            throw new ForgeReadFailed('GitHub answered labels this forge cannot read whole.');
        }

        return new IssueLabels(...array_map(fn (mixed $label): string => $this->text(is_array($label) ? $label : [], 'name'), array_values($nodes)));
    }

    /**
     * @param array<mixed> $issue
     *
     * @return array<array<mixed>>
     */
    private function eventsOf(array $issue): array
    {
        $timeline = $this->at($issue, 'timelineItems');
        $events = is_array($timeline) ? ($timeline['nodes'] ?? null) : null;
        // The flag must be false, because only a page that says it is the last proves the history whole.
        if (!is_array($timeline) || !is_array($events) || false !== $this->at($timeline, 'pageInfo', 'hasNextPage')) {
            throw new ForgeReadFailed('GitHub answered a label history this forge cannot read whole.');
        }

        return array_map(static fn (mixed $event): array => is_array($event) ? $event : throw new ForgeReadFailed('GitHub listed a label event that is not an object.'), $events);
    }

    /**
     * @param array<mixed> $event
     */
    private function kindOf(array $event): string
    {
        $kind = $event['__typename'] ?? null;

        // An event of another kind fails instead of counting as a removal, because a removal GitHub did not record would drop a label's applier.
        return is_string($kind) && in_array($kind, [self::LABELED, self::UNLABELED], true) ? $kind : throw new ForgeReadFailed('GitHub listed a label event of a kind this forge does not read.');
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

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }
}
