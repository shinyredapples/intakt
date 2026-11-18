<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewTool;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\StreamedTextBatch;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(LiveProgress::class)]
#[UsesClass(ReviewTool::class)]
#[UsesClass(StreamedTextBatch::class)]
#[UsesClass(ReviewerConversation::class)]
final class LiveProgressTest extends TestCase
{
    private const string REVIEWER = '0195f0aa-0000-7000-8000-000000000001';

    private RecordedRoundUpdates $updates;

    public function testAFileReadOverMcpReachesTheLiveLogAsReadAndItsPathWithoutTheToolsName(): void
    {
        $this->whenTheReviewerCalls(new ToolCall('1', ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file', ['path' => 'src/discount.js']));

        self::assertSame(['tool '.self::REVIEWER.' read src/discount.js'], $this->updates->seen);
    }

    public function testAFileReadInProcessReachesTheLiveLogInTheSameWords(): void
    {
        $this->whenTheReviewerCalls(new ToolCall('1', 'read_file', ['path' => 'src/discount.js']));

        self::assertSame(['tool '.self::REVIEWER.' read src/discount.js'], $this->updates->seen);
    }

    public function testACallOfAToolNoReviewerHasReachesTheLiveLogWithoutItsName(): void
    {
        $this->whenTheReviewerCalls(new ToolCall('1', 'review_write_file', ['path' => 'a']));

        self::assertSame(['tool '.self::REVIEWER.' used a tool'], $this->updates->seen);
    }

    protected function setUp(): void
    {
        $this->updates = new RecordedRoundUpdates();
    }

    private function whenTheReviewerCalls(ToolCall $call): void
    {
        $progress = null;
        $execution = self::createStub(ExecutionInterface::class);
        $execution->method('onProgress')->willReturnCallback(static function (callable $callback) use (&$progress, $execution): ExecutionInterface {
            $progress = Closure::fromCallable($callback);

            return $execution;
        });

        new LiveProgress($this->updates)->reporting($execution, new ReviewerConversation('You review.', new UuidV7(), new UuidV7(), Uuid::fromString(self::REVIEWER)));

        self::assertInstanceOf(Closure::class, $progress);
        $progress(new Progress(Progress::STAGE_TOOL_CALL, payload: $call));
    }
}
