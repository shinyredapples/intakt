<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[IsGrantedTool(ToolAccess::Write->value)]
final class WriteTool
{
    public function __invoke(): void
    {
    }
}
