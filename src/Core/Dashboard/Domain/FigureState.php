<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The value is the figure's class on the board, so a down source and an unhealthy hub stand out without logic in the template.
enum FigureState: string
{
    case Fine = 'fine';
    case Unhealthy = 'unhealthy';
    case Down = 'down';
}
