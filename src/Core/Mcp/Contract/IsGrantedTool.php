<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

use Attribute;

// The MCP Bundle in use ships no such attribute; this one is checked by AuthorizedToolCalls through Intakt's voter.
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class IsGrantedTool
{
    public function __construct(
        public ToolAccess $access,
    ) {
    }
}
