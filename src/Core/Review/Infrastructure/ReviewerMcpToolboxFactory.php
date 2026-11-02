<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ServeReviewToolsCommand;
use LogicException;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Psr\Http\Client\ClientInterface;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\AiBundle\Mcp\ConnectionToolset;
use Symfony\AI\McpBundle\Client\ServerConnection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReviewerMcpToolboxFactory
{
    // McpToolbox refuses an empty prefix and falls back to the server name, so the model sees the tools as review_read_file.
    public const string TOOL_PREFIX = 'review_';

    private const string CLIENT = 'intakt-reviewer';

    private const string SERVER = 'review';

    public function __construct(
        private ReviewCredential $credential,
        private ClientInterface $http,
        #[Autowire('%env(REVIEW_MCP_BASE_URL)%')]
        private string $baseUrl,
    ) {
    }

    // Built per request because the bearer credential names one round: that is how the server serves only the head under review.
    public function create(ReviewRequest $request): McpToolbox
    {
        $round = $request->conversation->round ?? throw new LogicException('A review over MCP needs the round whose head is under review.');
        $transport = new HttpTransport(
            rtrim($this->baseUrl, '/').ServeReviewToolsCommand::PATH,
            ['Authorization' => 'Bearer '.$this->credential->issueFor($round->toRfc4122())],
            $this->http,
        );
        $client = Client::builder()->setClientInfo(self::CLIENT, '1')->build();

        return new McpToolbox(new ConnectionToolset(new ServerConnection(self::CLIENT, self::SERVER, $client, $transport)), self::TOOL_PREFIX);
    }
}
