<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;

// Every item edited last by its author and every label applied by `ana`, with each read kept, because a test must see which histories a tick read.
final class ScriptedHistories implements IssueHistories
{
    private const array LABELS = ['intakt', 'ready', 'priority:high'];

    /** @var list<int> */
    public array $read = [];

    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        $this->read[] = $number;

        return new IssueHistory('ana', array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), self::LABELS));
    }
}
