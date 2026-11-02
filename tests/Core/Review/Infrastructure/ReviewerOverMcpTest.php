<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\KernelHttpClient;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;

#[Group('persistence')]
#[CoversClass(ReviewerMcpToolboxFactory::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(ReviewCredential::class)]
#[UsesClass(ReviewedRepository::class)]
#[UsesClass(Checkout::class)]
final class ReviewerOverMcpTest extends KernelTestCase
{
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private ReviewRound $round;

    private ReviewCredential $credential;

    /**
     * @var list<RequestInterface>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->round = new RoundFixture(self::getContainer())->start(1);
        $credential = self::getContainer()->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);
        $this->credential = $credential;
    }

    public function testAReviewWithToolsOverMcpReachesTheSameVerdictAsWithTheToolsInProcess(): void
    {
        $inProcess = $this->review(new ReviewerToolboxFactory()->create(...), 'read_file');
        $overMcp = $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertEquals($inProcess, $overMcp);
        self::assertSame(Verdict::class, $overMcp::class);
    }

    public function testTheFileTheModelReadsOverMcpIsTheOneAtTheHeadOfTheRound(): void
    {
        $toolbox = $this->mcpToolboxes()->create($this->request());

        $result = $toolbox->execute(new ToolCall('1', ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file', ['path' => 'RoundFixture.php']))->getResult();

        self::assertIsString($result);
        self::assertStringContainsString('final readonly class RoundFixture', $result);
    }

    public function testAMalformedToolCallOverMcpStillEndsTheReviewAsInvalidOutput(): void
    {
        $verdict = $this->review($this->mcpToolboxes()->create(...), 'no_such_tool');

        self::assertStringContainsString('malformed tool call', $verdict->findings[0]->text);
    }

    public function testAToolCallOverMcpWithoutItsRequiredArgumentIsAMalformedCallAsInProcess(): void
    {
        $inProcess = $this->review(new ReviewerToolboxFactory()->create(...), 'read_file', []);
        $overMcp = $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file', []);

        self::assertStringContainsString('malformed tool call', $inProcess->findings[0]->text);
        self::assertEquals($inProcess, $overMcp);
    }

    public function testEveryMcpRequestCarriesTheCredentialOfTheRoundUnderReview(): void
    {
        $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertNotSame([], $this->sent);

        foreach ($this->sent as $request) {
            self::assertSame('Bearer '.$this->credential->issueFor($this->round->id()->toRfc4122()), $request->getHeaderLine('Authorization'));
        }
    }

    public function testAReviewWithoutARoundIsRefusedBecauseNoCredentialCouldNameItsHead(): void
    {
        $request = new ReviewRequest(new Checkout($this->round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), []), new ReviewerConversation('You review for correctness.', new UuidV7()));

        $this->expectException(\LogicException::class);

        $this->mcpToolboxes()->create($request);
    }

    private function mcpToolboxes(): ReviewerMcpToolboxFactory
    {
        $kernel = new KernelHttpClient(self::$kernel ?? throw new \LogicException('No kernel.'));
        $http = new class($kernel, $this->sent) implements ClientInterface {
            /**
             * @param list<RequestInterface> $sent
             */
            public function __construct(private readonly ClientInterface $inner, private array &$sent)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return $this->inner->sendRequest($request);
            }
        };

        return new ReviewerMcpToolboxFactory($this->credential, $http, 'http://localhost');
    }

    /**
     * @param array<string, string> $arguments
     * @param \Closure(ReviewRequest): \Symfony\AI\Agent\Toolbox\ToolboxInterface $toolboxes
     */
    private function review(\Closure $toolboxes, string $tool, array $arguments = ['path' => 'RoundFixture.php']): Verdict
    {
        $step = 0;
        $script = static function () use (&$step, $tool, $arguments) {
            return 0 === $step++ ? new ToolCallResult([new ToolCall('1', $tool, $arguments)]) : self::APPROVE;
        };
        $platform = MockPlatformFactory::createPlatform($script, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), $toolboxes, new InMemoryMessageStore());

        return $reviewer->review($this->request(), $platform, 'reviewer-model');
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout($this->round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), []), new ReviewerConversation('You review for correctness.', new UuidV7(), $this->round->id()));
    }
}
