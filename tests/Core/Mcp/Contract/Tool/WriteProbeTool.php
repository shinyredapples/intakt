<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract\Tool;

use Intakt\Core\Mcp\Contract\ToolAccess;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'test_write_probe',
    description: 'A write tool that only the test kernel serves.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Write->value)]
final readonly class WriteProbeTool
{
    public function __invoke(): string
    {
        throw new LogicException('The write probe ran although the voter refuses writes.');
    }
}
