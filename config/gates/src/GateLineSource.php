<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because only a line the child wrote itself vouches for what the child kept; a line the runner built names a crash, a timeout or a missing line.
enum GateLineSource: string
{
    case Child = 'child';
    case Runner = 'runner';
}
