<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewServerAccesses;
use Intakt\Core\Review\UseCase\ServeReviewTools\IssueReviewCredentialCommand;
use Intakt\Core\Review\UseCase\ServeReviewTools\ListDirectoryTool;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReadFileTool;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\SearchFilesTool;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class IssuedReviewServerAccess implements ReviewServerAccesses
{
    private const array TOOLS = [ReadFileTool::class, ListDirectoryTool::class, SearchFilesTool::class];

    public function __construct(
        private ReviewCredential $credential,
        #[Autowire('%env(REVIEW_MCP_BASE_URL)%')]
        private string $baseUrl,
    ) {
    }

    #[Override]
    public function forRound(?Uuid $round): ?ReviewServerAccess
    {
        if (!$round instanceof Uuid || '' === $this->baseUrl) {
            return null;
        }

        try {
            $credential = $this->credential->issueFencedFor($round->toRfc4122());
        } catch (LogicException) {
            // An empty APP_SECRET issues none, and the server would refuse every request anyway.
            return null;
        }

        return new ReviewServerAccess($this->baseUrl.IssueReviewCredentialCommand::PATH, $credential, $this->tools());
    }

    /**
     * @return list<string>
     */
    private function tools(): array
    {
        return array_map(static fn (string $class): string => array_first(new ReflectionClass($class)->getAttributes(McpTool::class))?->newInstance()->name ?? throw new LogicException($class.' serves no MCP tool name.'), self::TOOLS);
    }
}
