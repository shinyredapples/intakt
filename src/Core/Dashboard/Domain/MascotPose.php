<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The value names the octopus image of the pose, so a pose and its files share one name.
enum MascotPose: string
{
    case Waiting = 'waiting';
    case Conducting = 'conducting';
    case Bowing = 'bowing';
}
