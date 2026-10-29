<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class PanelReviewer
{
    /**
     * @param positive-int $budget
     */
    public function __construct(
        public ReviewerRole $role,
        public ReviewerModel $model,
        public int $budget,
    ) {
    }
}
