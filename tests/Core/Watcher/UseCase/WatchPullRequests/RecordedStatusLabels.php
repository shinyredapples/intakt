<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;

// The labels a pull request carries on the forge, so a test reads them before and after a pass; a read can be made to fail as GitHub's does.
final class RecordedStatusLabels implements StatusLabels
{
    /** @var array<string, list<string>> */
    public array $labels = [];

    public int $readsToFail = 0;

    public int $writes = 0;

    public function match(string $project, ChangeId $change, ?RoundStatusLabel $wanted): void
    {
        if ($this->readsToFail > 0) {
            --$this->readsToFail;

            throw new ForgeReadFailed('The labels of '.$change->value.' could not be read.');
        }

        $now = $wanted instanceof RoundStatusLabel ? [$wanted->value] : [];

        if (($this->labels[$change->value] ?? []) !== $now) {
            ++$this->writes;
        }

        $this->labels[$change->value] = $now;
    }

    /**
     * @return list<string>
     */
    public function on(string $change): array
    {
        return $this->labels[$change] ?? [];
    }
}
