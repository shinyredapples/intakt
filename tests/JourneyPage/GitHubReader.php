<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use DateTimeZone;

// The API's answers are untrusted, so a wrong shape must fail explicitly rather than fall back to a default.
final readonly class GitHubReader
{
    private const string MOMENT = 'Y-m-d\\TH:i:sp';

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
     * @param array<mixed> $item
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
     * @return list<array<mixed>>
     */
    private function items(JourneyResource $resource): array
    {
        $items = array_merge([], ...array_map(fn (mixed $page): array => $this->listOf($page, $resource), $this->pages($resource)));

        return array_map(fn (mixed $item): array => is_array($item) && !array_is_list($item) ? $item : throw $this->malformed($resource), $items);
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value, JourneyResource $resource): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw $this->malformed($resource);
    }

    /**
     * @return list<mixed>
     */
    private function pages(JourneyResource $resource): array
    {
        $json = $this->api->pages($resource);

        return $this->listOf(json_validate($json) ? json_decode($json, true) : null, $resource);
    }

    /**
     * @param array<mixed> $item
     */
    private function issueFrom(array $item): Issue
    {
        $milestone = $item['milestone'] ?? null;
        $number = is_array($milestone) ? $milestone['number'] ?? null : null;
        $number = is_int($number) ? $number : null;
        $reason = $item['state_reason'] ?? null;

        if ((null !== $milestone && null === $number) || !in_array($item['state'] ?? null, ['open', 'closed'], true)) {
            throw $this->malformed(JourneyResource::Issues);
        }

        $created = $this->moment($item, 'created_at') ?? throw $this->malformed(JourneyResource::Issues);
        $closed = 'closed' === $item['state'] ? $this->moment($item, 'closed_at') : null;

        if ('closed' === $item['state'] && !$closed instanceof DateTimeImmutable) {
            throw $this->malformed(JourneyResource::Issues);
        }

        return new Issue($number, $created, $closed, in_array($reason, ['not_planned', 'duplicate'], true));
    }

    /**
     * @param array<mixed> $item
     */
    private function moment(array $item, string $key): ?DateTimeImmutable
    {
        $text = $item[$key] ?? null;

        if (null === $text) {
            return null;
        }

        $moment = is_string($text) ? DateTimeImmutable::createFromFormat(self::MOMENT, $text, new DateTimeZone('UTC')) : false;

        return false === $moment || !$this->parsedCleanly() ? throw new JourneyInputFailed('the GitHub answer holds a date that is not a UTC moment: '.json_encode($text)) : $moment->setTimezone(new DateTimeZone('UTC'));
    }

    private function parsedCleanly(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();

        return false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']);
    }

    private function malformed(JourneyResource $resource): JourneyInputFailed
    {
        return new JourneyInputFailed('the GitHub answer for '.$resource->value.' is not a list of pages of objects with the fields the journey page reads');
    }
}
