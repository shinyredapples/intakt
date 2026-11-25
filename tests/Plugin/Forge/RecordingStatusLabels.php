<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\StatusLabels;

final class RecordingStatusLabels implements StatusLabels
{
    /** @var list<string> */
    public array $writes = [];

    public function reconcile(string $project, ChangeId $change): void
    {
        $this->writes[] = 'reconcile '.$change->value;
    }
}
