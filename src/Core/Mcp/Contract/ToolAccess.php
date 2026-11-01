<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

enum ToolAccess: string
{
    case Read = 'read';
    case Write = 'write';
}
