<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface Panels
{
    /**
     * @throws PanelNotConfigured
     * @throws GlobCannotBeEvaluated
     */
    public function panelForChange(string $project, ChangeDescription $change): Panel;
}
