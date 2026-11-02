<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\ClientGateway;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'search_files',
    description: 'Finds the lines of the repository at the head under review that contain a text; answers path:line:text. Send a progressToken to get progress notifications.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class SearchFilesTool
{
    // Because a large tree has thousands of files, and a notification for each would flood the client.
    private const int FILES_PER_NOTIFICATION = 10;

    public function __construct(private ReviewedRepository $repository)
    {
    }

    public function __invoke(
        ClientGateway $client,
        #[Schema(description: 'Text to find, matched literally and case-sensitively.')] string $text,
        #[Schema(description: 'File or directory to search, relative to the repository root; "." is the root.')] string $path = '.',
    ): string {
        $progress = static function (int $filesSearched) use ($client): void {
            // The count only grows, as MCP progress values must.
            if (0 === $filesSearched % self::FILES_PER_NOTIFICATION) {
                $client->progress($filesSearched, null, sprintf('%d files searched.', $filesSearched));
            }
        };

        return $this->repository->reporting($progress)->searchFiles($text, $path);
    }
}
