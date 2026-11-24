<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;

final class RecordingStatusLabels implements StatusLabels
{
    /** @var list<string> */
    public array $writes = [];

    public function mirror(string $project, ChangeId $change, RoundStatusLabel $status): void
    {
        $this->writes[] = 'mirror '.$change->value.' '.$status->value;
    }

    public function restore(string $project, ChangeId $change): void
    {
        $this->writes[] = 'restore '.$change->value;
    }
}
