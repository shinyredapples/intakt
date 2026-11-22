<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewTools;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Review\Contract\NoReviewServerAccess;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\UseCase\ServeReviewTools\ListDirectoryTool;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReadFileTool;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredentialNeedsSecret;
use Intakt\Core\Review\UseCase\ServeReviewTools\SearchFilesTool;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class IssuedReviewServerAccesses implements ReviewServerAccesses
{
    // The tools the review server serves; each is read only and its name is read from its own attribute, so it is spelled once.
    private const array TOOLS = [ReadFileTool::class, ListDirectoryTool::class, SearchFilesTool::class];

    /**
     * @param non-empty-string $url
     */
    public function __construct(
        private ReviewCredential $credential,
        #[Autowire(param: ReviewServices::MCP_URL)]
        private string $url,
    ) {
    }

    #[Override]
    public function forRound(Uuid $round): ReviewServerAccess
    {
        return new ReviewServerAccess('intakt-review', $this->url, $this->bearerFor($round), new ReviewTools(...array_map($this->nameOf(...), self::TOOLS)));
    }

    // Typed, because an empty APP_SECRET is a state of the install the vendor turns into a blocked review, not a programming error.
    private function bearerFor(Uuid $round): string
    {
        try {
            return $this->credential->issueFor($round->toRfc4122());
        } catch (ReviewCredentialNeedsSecret $unsigned) {
            throw NoReviewServerAccess::because($unsigned);
        }
    }

    /**
     * @param class-string $tool
     */
    private function nameOf(string $tool): string
    {
        return array_first(new ReflectionClass($tool)->getAttributes(McpTool::class))?->newInstance()->name ?? throw new LogicException(sprintf('%s names no tool.', $tool));
    }
}
