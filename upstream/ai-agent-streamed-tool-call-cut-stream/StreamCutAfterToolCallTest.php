<?php

declare(strict_types=1);

namespace Intakt\Upstream\AgentStreamedToolCallCutStream;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[AsTool('lookup', 'Looks up a fact')]
final class LookupTool
{
    public int $runs = 0;

    public function __invoke(): string
    {
        ++$this->runs;

        return 'the fact';
    }
}

// Round one streams a tool call and its usage; round two streams its usage and half a sentence, then the connection drops.
#[CoversNothing]
final class StreamCutAfterToolCallTest extends TestCase
{
    private const int TOOL_ROUND_TOKENS = 110;

    private const int CUT_ROUND_TOKENS = 120;

    private LookupTool $tool;

    /** @var list<DeferredResult> */
    private array $rounds = [];

    private int $calls = 0;

    protected function setUp(): void
    {
        $this->tool = new LookupTool();
    }

    public function testTheExecutionFailsWhenTheStreamIsCutMidText(): void
    {
        $execution = $this->runUntilTheCut();

        self::assertSame(ExecutionStatus::Failed, $execution->getStatus());
        self::assertSame('The stream ended before its terminal event.', $execution->getError());
    }

    public function testTheToolRunsOnce(): void
    {
        $this->runUntilTheCut();

        self::assertSame(1, $this->tool->runs);
        self::assertSame(2, $this->calls);
    }

    public function testEachRoundReportsItsUsageOnce(): void
    {
        $this->runUntilTheCut();

        self::assertSame(
            [self::TOOL_ROUND_TOKENS, self::CUT_ROUND_TOKENS],
            array_map(fn (DeferredResult $round): int => $this->totalTokens($round->getResult()->getMetadata()->get('token_usage')), $this->rounds),
        );
    }

    // The control case: without the cut, the streamed execution adds up the usage of both rounds once.
    public function testACompleteExecutionCountsTheUsageOfItsRoundsOnce(): void
    {
        $execution = $this->start($this->completeRound());

        foreach ($execution->getContent() as $delta) {
            self::assertInstanceOf(TextDelta::class, $delta);
        }

        self::assertSame(self::TOOL_ROUND_TOKENS + self::CUT_ROUND_TOKENS, $this->totalTokens($execution->getMetadata()->get('token_usage')));
    }

    // Both rounds were billed, and the execution's metadata is documented to fill while the deltas are read, as a cut StreamResult's does.
    public function testTheFailedExecutionCountsTheUsageOfItsRoundsOnce(): void
    {
        $execution = $this->runUntilTheCut();

        self::assertSame(self::TOOL_ROUND_TOKENS + self::CUT_ROUND_TOKENS, $this->totalTokens($execution->getMetadata()->get('token_usage')));
    }

    private function runUntilTheCut(): ExecutionInterface
    {
        $execution = $this->start($this->cutRound());

        try {
            foreach ($execution->getContent() as $delta) {
                self::assertInstanceOf(TextDelta::class, $delta);
            }
        } catch (IncompleteStreamException) {
            return $execution;
        }

        self::fail('The cut stream did not fail the execution.');
    }

    private function start(Generator $lastRound): ExecutionInterface
    {
        $events = new EventDispatcher();
        $events->addListener(ResultEvent::class, function (ResultEvent $event): void {
            $this->rounds[] = $event->getDeferredResult();
        });
        $answer = fn (): StreamResult => new StreamResult(1 === ++$this->calls ? $this->toolRound() : $lastRound);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: $events);

        return new Agent($platform, 'probe-model', toolbox: new Toolbox([$this->tool]))
            ->call(new MessageBag(Message::ofUser('Tell me the fact.')), ['stream' => true]);
    }

    private function toolRound(): Generator
    {
        yield new ToolCallComplete([new ToolCall('call-1', 'lookup')]);
        yield new TokenUsage(promptTokens: 100, completionTokens: 10, totalTokens: self::TOOL_ROUND_TOKENS);
    }

    private function cutRound(): Generator
    {
        yield new TokenUsage(promptTokens: self::CUT_ROUND_TOKENS, totalTokens: self::CUT_ROUND_TOKENS);
        yield new TextDelta('The fact is');

        throw new IncompleteStreamException('The stream ended before its terminal event.');
    }

    private function completeRound(): Generator
    {
        yield new TokenUsage(promptTokens: self::CUT_ROUND_TOKENS, totalTokens: self::CUT_ROUND_TOKENS);
        yield new TextDelta('The fact is known.');
    }

    private function totalTokens(mixed $usage): int
    {
        if ($usage instanceof TokenUsageAggregation) {
            return array_sum(array_map(static fn (TokenUsageInterface $part): int => $part->getTotalTokens() ?? 0, $usage->getTokenUsages()));
        }

        return $usage instanceof TokenUsageInterface ? $usage->getTotalTokens() ?? 0 : 0;
    }
}
