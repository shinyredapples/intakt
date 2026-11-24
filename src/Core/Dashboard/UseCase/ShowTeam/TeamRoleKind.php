<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

enum TeamRoleKind: string
{
    case Developer = 'developer';
    case Reviewer = 'reviewer';
}
