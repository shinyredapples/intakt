<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
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
    // Run once on the next history read, so a test can force what another worker does while a tick reads a history.
    public ?Closure $during = null;

    public function __construct(private readonly RecordedIssueReads $reads)
    {
    }

    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        $this->read[] = $number;
        $during = $this->during;
        $this->during = null;
        if ($during instanceof Closure) {
            $during();
        }

        $listed = $this->reads->lastListed[$number] ?? throw new LogicException(sprintf('#%d was never listed.', $number));

        return new IssueHistory('ana', array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), [...$listed->labels]), $listed->body, $listed->labels);
    }
}
