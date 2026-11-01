<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\TraceableToolbox;
use Symfony\AI\AiBundle\Profiler\DataCollector;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Component\Uid\UuidV7;

// A trait, because the tests of the reviewer agent, its prompt and its toolboxes all prove their part on the same scripted review through the whole stack.
trait ScriptedReviews
{
    private const string COLLECTOR = 'test.reviewer_verdict.ai_data_collector';

    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private ReviewCheckout $fixture;

    private MessageStoreInterface $conversations;

    private UuidV7 $conversation;

    /**
     * @var list<Finding>
     */
    private array $open = [];

    /**
     * @var list<string>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
        $this->conversations = new InMemoryMessageStore();
        $this->conversation = new UuidV7();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    // Because the script asks for one tool call per round, the answer follows the last call.
    private function review(string $task, array $toolCalls, string $answer): Verdict
    {
        return $this->reviewRounds($task, array_map(static fn (ToolCall $call): array => [$call], $toolCalls), $answer);
    }

    /**
     * @param list<list<ToolCall>> $rounds
     */
    // Because one model answer can ask for several tools at once, a round is a list of calls, and the verdict follows the last round.
    private function reviewRounds(string $task, array $rounds, string $answer): Verdict
    {
        $script = function (mixed $model, mixed $input) use (&$rounds, $answer): string|ResultInterface {
            $this->sent[] = json_encode($input, JSON_THROW_ON_ERROR);
            $next = array_shift($rounds);

            return null === $next ? $answer : new ToolCallResult($next);
        };
        $platform = new TraceablePlatform(MockPlatformFactory::createPlatform($script, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher));
        $toolbox = new TraceableToolbox(new ReviewerToolboxFactory()->create($this->fixture->checkout));
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new VerdictParser()), static fn (): TraceableToolbox => $toolbox, $this->conversations);

        $verdict = $reviewer->review(new ReviewRequest($this->fixture->checkout, new ReviewBrief($task, $this->open), new ReviewerConversation('You review for correctness.', $this->conversation)), $platform, 'reviewer-model');

        // Because the toolbox is built per review and the platform per test, the collector is built from this run's platform instead of a profiled request.
        $collector = new DataCollector([$platform], [$toolbox], [$toolbox], [], [], []);
        $collector->lateCollect();
        self::getContainer()->set(self::COLLECTOR, $collector);

        return $verdict;
    }

    private function openFinding(string $id, ?string $symbol = null): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, $symbol, 'Text '.$id, FixableBy::Builder, false, null, null);
    }
}
