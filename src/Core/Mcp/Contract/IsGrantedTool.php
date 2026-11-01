<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

use Attribute;

// Defined here because the installed MCP Bundle ships no such attribute; AuthorizedToolCalls checks it through Intakt's voter.
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class IsGrantedTool
{
    public function __construct(
        public ToolAccess $access,
    ) {
    }
}
