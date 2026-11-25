<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Symfony\Component\HttpClient\Response\MockResponse;

// The labels of one pull request as GitHub keeps them: each application is in the history under its account, and a removal or a comment is answered like GitHub does.
final class RecordedPullRequestLabels implements IssueHistories
{
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<string> */
    public array $labels = [];

    /** @var list<string> */
    public array $comments = [];

    /** @var list<LabelApplication> */
    private array $applications = [];

    public function __construct(
        private readonly string $issuePath,
        private readonly string $own,
    ) {
    }

    public function applied(string $label, string $actor): void
    {
        $this->labels[] = $label;
        $this->applications[] = new LabelApplication($label, $actor);
    }

    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        return new IssueHistory(RecordedTrust::WRITER, $this->applications, '', new IssueLabels(...$this->labels));
    }

    public function answers(string $path): bool
    {
        return str_starts_with($path, $this->issuePath.'/');
    }

    public function answer(string $method, string $path, string $body): MockResponse
    {
        return 'DELETE' === $method ? $this->removal($path) : $this->comment($body);
    }

    private function removal(string $path): MockResponse
    {
        $this->labels = array_values(array_diff($this->labels, [rawurldecode(basename($path))]));

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
