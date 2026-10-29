<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final class PanelWithoutReviewers extends PanelConfigurationFailure
{
    public function __construct()
    {
        parent::__construct('A panel needs at least one reviewer.');
    }
}
