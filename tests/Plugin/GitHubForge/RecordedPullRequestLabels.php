<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Symfony\Component\HttpClient\Response\MockResponse;

// The labels of one pull request as GitHub keeps them: each write changes the labels and adds an event under the writer's identity.
final class RecordedPullRequestLabels
{
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<string> */
    public array $labels = [];

    /** @var list<string> */
    public array $comments = [];

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

    public function removed(string $label, string $actor): void
    {
        $this->labels = array_values(array_diff($this->labels, [$label]));
        $this->events[] = ['event' => 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    public function answers(string $path): bool
    {
        return str_starts_with($path, $this->issuePath.'/');
    }

    public function answer(string $method, string $path, string $body): MockResponse
    {
        return match (true) {
            'GET' === $method && str_ends_with($path, '/labels') => $this->listing(array_map(static fn (string $label): array => ['name' => $label], $this->labels)),
            'GET' === $method && str_ends_with($path, '/events') => $this->listing($this->events),
            str_ends_with($path, '/comments') => $this->comment($body),
            'DELETE' === $method => $this->removal($path),
            default => $this->addition($body),
        };
    }

    /**
     * @param list<array<mixed>> $entries
     */
    private function listing(array $entries): MockResponse
    {
        return new MockResponse(json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function comment(string $body): MockResponse
    {
        $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $stored = [...$payload, 'user' => ['login' => $this->own, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function removal(string $path): MockResponse
    {
        $this->removed(rawurldecode(basename($path)), $this->own);

        return new MockResponse('[]');
    }

    private function addition(string $body): MockResponse
    {
        foreach ((array) ((array) json_decode($body, true, flags: JSON_THROW_ON_ERROR))['labels'] as $label) {
            $this->applied(is_string($label) ? $label : '', $this->own);
        }

        return new MockResponse('[]');
    }
}
