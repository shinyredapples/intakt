<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[IsGrantedTool(ToolAccess::Read->value)]
final class ReadThenWriteTool
{
    #[IsGrantedTool(ToolAccess::Write->value)]
    public function __invoke(): void
    {
    }
}
