<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: self::NAME,
    description: 'Reads one file of the repository at the head under review.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class ReadFileTool
{
    // A constant, because the review server records each call under the tool's name and the summary counts by it.
    public const string NAME = 'read_file';

    public function __construct(private ReviewedRepository $repository)
    {
    }

    public function __invoke(
        #[Schema(description: 'Path of the file, relative to the repository root.')] string $path,
    ): string {
        return $this->repository->readFile($path);
    }
}
