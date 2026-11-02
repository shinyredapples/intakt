<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewRequest;
use LogicException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReviewerMcpToolboxFactory
{
    // The prefix of the mcp_server entry in config/packages/ai.php; set because the default is the server name, which the tool names would carry anyway.
    public const string TOOL_PREFIX = 'review_';

    public function __construct(
        #[Autowire(service: 'ai.toolbox.reviewer.mcp.reviewer.review')]
        private ToolboxInterface $tools,
        private ReviewServerHttpClient $http,
    ) {
    }

    // Per request because the bearer credential names one round: that is how the server serves only the head under review.
    public function create(ReviewRequest $request): ToolsOfRound
    {
        $round = $request->conversation->round ?? throw new LogicException('A review over MCP needs the round whose head is under review.');

        return new ToolsOfRound($this->tools, $this->http, $round);
    }
}
