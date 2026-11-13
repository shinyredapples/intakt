<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

// Everything the API answers is untrusted: a wrong shape is an explicit failure, never a default.
final readonly class GitHubReader
{
    public function __construct(private GitHubApi $api)
    {
    }

    /**
     * @return list<Milestone>
     */
    public function milestones(): array
    {
        return array_map($this->milestoneFrom(...), $this->items(JourneyResource::Milestones));
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        $issues = array_filter($this->items(JourneyResource::Issues), static fn (array $item): bool => !array_key_exists('pull_request', $item));

        return array_values(array_map($this->issueFrom(...), $issues));
    }

    /**
     * @return list<PullRequest>
     */
    public function pullRequests(): array
    {
        return array_map(fn (array $item): PullRequest => new PullRequest($this->moment($item, 'merged_at')), $this->items(JourneyResource::PullRequests));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(JourneyResource $resource): array
    {
        $json = $this->api->pages($resource);
        $pages = json_validate($json) ? json_decode($json, true) : null;

        if (!is_array($pages) || !array_is_list($pages)) {
            throw $this->malformed($resource);
        }

        $items = [];

        foreach ($pages as $page) {
            foreach (is_array($page) && array_is_list($page) ? $page : throw $this->malformed($resource) as $item) {
                $items[] = is_array($item) && !array_is_list($item) ? $item : throw $this->malformed($resource);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function milestoneFrom(array $item): Milestone
    {
        $number = $item['number'] ?? null;
        $title = $item['title'] ?? null;
        $goal = $item['description'] ?? '';

        if (!is_int($number) || !is_string($title) || !is_string($goal)) {
            throw $this->malformed(JourneyResource::Milestones);
        }

        return new Milestone($number, $title, $goal, $this->moment($item, 'due_on'));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function issueFrom(array $item): Issue
    {
        $milestone = $item['milestone'] ?? null;
        $number = is_array($milestone) ? $milestone['number'] ?? null : null;
        $reason = $item['state_reason'] ?? null;

        if ((null !== $milestone && !is_int($number)) || !in_array($item['state'] ?? null, ['open', 'closed'], true)) {
            throw $this->malformed(JourneyResource::Issues);
        }

        $closed = 'closed' === $item['state'] ? $this->moment($item, 'closed_at') : null;

        if ('closed' === $item['state'] && null === $closed) {
            throw $this->malformed(JourneyResource::Issues);
        }

        return new Issue($number, $closed, 'not_planned' === $reason);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function moment(array $item, string $key): ?DateTimeImmutable
    {
        $text = $item[$key] ?? null;

        if (null === $text) {
            return null;
        }

        try {
            return is_string($text) ? new DateTimeImmutable($text, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('UTC')) : throw $this->malformed(JourneyResource::Issues);
        } catch (Throwable) {
            throw $this->malformed(JourneyResource::Issues);
        }
    }

    private function malformed(JourneyResource $resource): JourneyInputFailed
    {
        return new JourneyInputFailed('the GitHub answer for '.$resource->value.' is not a list of pages of objects with the fields the journey page reads');
    }
}
