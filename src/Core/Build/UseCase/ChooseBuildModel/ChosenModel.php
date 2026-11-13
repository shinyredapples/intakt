<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Build\Contract\TierChoice;

final readonly class ChosenModel
{
    public function __construct(
        public TierChoice $choice,
        public ResolvedModel $model,
    ) {
    }
}
