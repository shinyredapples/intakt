<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;

// Every history as edited last by `ana` with the given labels applied by her, and every read counted, because a test must see whose history a tick read.
final class RecordedIssueHistories implements IssueHistories
{
    /** @var list<int> */
    public array $read = [];
    public bool $failing = false;
    // Run once on the next read, before it answers, so a test can force what another worker does while a history is read.
    public ?Closure $during = null;

    /**
     * @param list<string> $labels
     */
    public function __construct(
        private readonly array $labels = ['intakt', 'ready'],
    ) {
    }

    public function of(string $project, int $number): IssueHistory
    {
        $this->read[] = $number;
        $during = $this->during;
        $this->during = null;
        if ($during instanceof Closure) {
            $during();
        }

        if ($this->failing) {
            throw new ForgeReadFailed('GitHub answered 502 to a history read.');
        }

        return new IssueHistory('ana', array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), $this->labels));
    }
}
