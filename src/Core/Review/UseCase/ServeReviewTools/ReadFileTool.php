<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'read_file',
    description: 'Reads one file of the repository at the head of a review round that is under review.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class ReadFileTool
{
    public function __construct(private ReviewedRepository $repository)
    {
    }

    public function __invoke(
        #[Schema(description: 'UUID of the review round under review.')] string $roundId,
        #[Schema(description: 'Path of the file, relative to the repository root.')] string $path,
    ): string {
        return $this->repository->readFile($roundId, $path);
    }
}
