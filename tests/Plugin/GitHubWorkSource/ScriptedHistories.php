<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use LogicException;

// Every item edited last by its author, every label applied by `ana`, and the text and labels it was last listed with, with each read kept, because a test must see which histories a tick read.
final class ScriptedHistories implements IssueHistories
{
    /** @var list<int> */
    public array $read = [];

    public function __construct(private readonly RecordedIssueReads $reads)
    {
    }

    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        $this->read[] = $number;
        $listed = $this->reads->lastListed[$number] ?? throw new LogicException(sprintf('#%d was never listed.', $number));

        return new IssueHistory('ana', array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), $listed->labels), $listed->body, $listed->labels);
    }
}
