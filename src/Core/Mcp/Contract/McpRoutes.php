<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

// The MCP bundle names the route of each server in config/packages/mcp.yaml, so other modules link to one by this name instead of spelling its path.
final readonly class McpRoutes
{
    public const string OWNER = '_mcp_endpoint_owner';
}
