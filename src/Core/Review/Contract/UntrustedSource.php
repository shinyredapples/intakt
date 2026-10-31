<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum UntrustedSource: string
{
    case Task = 'task';
    case ToolResult = 'tool result';
    case EarlierFindings = 'earlier findings';
}
