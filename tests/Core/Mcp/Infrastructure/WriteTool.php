<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\IsGrantedTool;
use Intakt\Core\Mcp\Contract\ToolAccess;

#[IsGrantedTool(ToolAccess::Write)]
final class WriteTool
{
    public function __invoke(): void
    {
    }
}
