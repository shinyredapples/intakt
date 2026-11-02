<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\AdmitHeads;

use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadChecks;

final class WrongHeadChecks implements HeadChecks
{
    public function ciStateOf(string $project, CommitId $head): CiState
    {
        return new CiState(new CommitId(str_repeat('f', 40)), []);
    }
}
