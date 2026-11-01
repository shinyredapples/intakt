<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ListProjects;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Mcp\Contract\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'list_projects',
    description: 'List the names of the projects Intakt is configured for.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class ListProjectsTool
{
    public function __construct(
        private TierResolver $resolver,
    ) {
    }

    public function __invoke(): ProjectList
    {
        return new ProjectList($this->resolver->projects());
    }
}
