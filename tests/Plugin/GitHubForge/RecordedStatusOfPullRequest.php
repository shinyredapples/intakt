<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Symfony\Component\HttpClient\Response\MockResponse;

// The labels, label events and comments of one pull request as GitHub answers them, so a watcher tick runs the real status adapter and a test reads what it left; a read can be made to fail as GitHub's does.
final class RecordedStatusOfPullRequest
{
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<string> */
    public array $labels = [];

    /** @var list<string> */
    public array $comments = [];

    public int $eventReadsToFail = 0;

    /** @var list<array<mixed>> */
    private array $events = [];

    public function __construct(
        private readonly string $issuePath,
        private readonly string $own,
    ) {
    }

    public function applied(string $label, string $actor): void
    {
        $this->labels[] = $label;
        $this->events[] = ['event' => 'labeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    public function answers(string $path): bool
    {
        return str_starts_with($path, $this->issuePath.'/');
    }

    public function answer(string $method, string $path, string $body): MockResponse
    {
        $what = substr($path, strlen($this->issuePath.'/'));

        return match (true) {
            'GET' === $method && 'labels' === $what => $this->listing(array_map(static fn (string $label): array => ['name' => $label], $this->labels)),
            'GET' === $method && 'events' === $what => $this->eventsRead(),
            'DELETE' === $method => $this->removal(rawurldecode(basename($path))),
            'comments' === $what => $this->comment($body),
            default => $this->addition($body),
        };
    }

    private function eventsRead(): MockResponse
    {
        if ($this->eventReadsToFail > 0) {
            --$this->eventReadsToFail;

            return new MockResponse('{"message":"unavailable"}', ['http_code' => 503]);
        }

        return $this->listing($this->events);
    }

    /**
     * @param list<array<mixed>> $entries
     */
    private function listing(array $entries): MockResponse
    {
        return new MockResponse(json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function removal(string $label): MockResponse
    {
        if (!in_array($label, $this->labels, true)) {
            return new MockResponse('{"message":"Label does not exist"}', ['http_code' => 404]);
        }

        $this->labels = array_values(array_diff($this->labels, [$label]));
        $this->events[] = ['event' => 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => $this->own], 'created_at' => self::MOMENT];

        return new MockResponse('[]');
    }

    private function addition(string $body): MockResponse
    {
        foreach ((array) ((array) json_decode($body, true, flags: JSON_THROW_ON_ERROR))['labels'] as $label) {
            $this->applied(is_string($label) ? $label : '', $this->own);
        }

        return new MockResponse('[]');
    }

    private function comment(string $body): MockResponse
    {
        $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $stored = [...$payload, 'user' => ['login' => $this->own, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }
}
