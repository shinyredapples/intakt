<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use LogicException;

// Logs and refuses every write, so a test sees each write that reached the forge and none can succeed by accident.
final class WriteRefusingForge implements ForgeWrites
{
    /** @var list<string> */
    public array $writeLog = [];

    public function postReview(string $project, ChangeId $change, string $body): void
    {
        $this->writeLog[] = sprintf('post review on %s %s', $project, $change->value);

        throw new LogicException('The test forge refuses every write.');
    }
}
