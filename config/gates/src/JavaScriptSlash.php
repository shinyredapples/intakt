<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because JavaScript reads a slash by what came before it, a scanner has to carry what the next slash means.
enum JavaScriptSlash: string
{
    case Regex = 'regex';
    case Division = 'division';
}
