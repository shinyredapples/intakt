<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use Override;

// The issues a split filed, kept by marker as the forge finds them, numbered from 100 in the order they were filed, so a test sees how many parts the owner would see.
final class FiledParts implements TaskParts
{
    /**
     * @var list<array{number: int, title: string, body: string, marker: string, labels: list<string>}>
     */
    public array $filed = [];

    // The create of this filing (counted from 1) is stored but answered as failed, as GitHub can answer 502 to an issue it created.
    public ?int $answerLostFor = null;

    public ?ForgeReadFailed $unreadable = null;

    #[Override]
    public function fileOnce(string $project, PartIssue $part): int
    {
        if ($this->unreadable instanceof ForgeReadFailed) {
            throw $this->unreadable;
        }

        $found = array_find($this->filed, static fn (array $issue): bool => $issue['marker'] === $part->marker);

        if (null !== $found) {
            return $found['number'];
        }

        $number = 100 + count($this->filed);
        $this->filed[] = ['number' => $number, 'title' => $part->title, 'body' => $part->body, 'marker' => $part->marker, 'labels' => [...$part->labels]];

        if (count($this->filed) === $this->answerLostFor) {
            $this->answerLostFor = null;

            throw new ForgeWriteFailed('GitHub answered 502 to a write.');
        }

        return $number;
    }

    /**
     * @return list<string>
     */
    public function titles(): array
    {
        return array_column($this->filed, 'title');
    }
}
