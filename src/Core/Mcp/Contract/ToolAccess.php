<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

// The values are Security attributes, prefixed so that no other isGranted() check can be answered by the tool voter.
enum ToolAccess: string
{
    case Read = 'mcp_tool_read';
    case Write = 'mcp_tool_write';
}
