<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final class PanelCannotReachQuorum extends PanelConfigurationFailure
{
    public function __construct(QuorumPolicy $quorum)
    {
        parent::__construct(sprintf('These reviewers can never meet the "%s" quorum, not even when all of them approve.', $quorum->value));
    }
}
