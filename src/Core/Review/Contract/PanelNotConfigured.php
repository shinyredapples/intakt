<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final class PanelNotConfigured extends PanelConfigurationFailure
{
    public function __construct(string $project)
    {
        parent::__construct(sprintf('Project "%s" has no review panel configured.', $project));
    }
}
