<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;

// A forge that answers like GitHub's conditional listing: the same validator gets `unchanged`, and only a full answer counts as a read.
final class RecordedForge implements OpenChanges
{
    /** @var array<string, list<OpenChange>> */
    private array $open = [];
    private int $revision = 0;
    public int $fullReads = 0;
    public int $conditionalAnswers = 0;
    /** @var list<string|null> */
    public array $received = [];

    public function opens(string $project, OpenChange ...$changes): void
    {
        $this->open[$project] = array_values($changes);
        ++$this->revision;
    }

    public function listOpen(string $project, ?string $validator): OpenChangeListing
    {
        $this->received[] = $validator;
        $current = $this->validatorOf($project);

        if ($validator === $current) {
            ++$this->conditionalAnswers;

            return OpenChangeListing::unchanged();
        }

        ++$this->fullReads;

        return OpenChangeListing::listed($this->open[$project] ?? [], $current);
    }

    public static function change(string $id, string $head, string $updatedAt): OpenChange
    {
        return new OpenChange(new ChangeId($id), new CommitId(str_repeat($head, 40)), new DateTimeImmutable($updatedAt));
    }

    private function validatorOf(string $project): string
    {
        return sprintf('W/"%s-%d"', $project, $this->revision);
    }
}
