<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(MalformedToolCalls::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(Fence::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(SearchFiles::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
final class MalformedToolCallsTest extends KernelTestCase
{
    use ScriptedReviews;

    public function testAToolCallWithoutItsArgumentEndsAsChangesEvenWhenTheModelApproves(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', [])], self::APPROVE);

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        self::assertStringContainsString('malformed tool call', $verdict->findings[0]->text);
        self::assertStringContainsString('is mandatory for tool', $this->sent[1]);
    }

    public function testACallToAnUnknownToolEndsAsChangesEvenWhenTheModelApproves(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'write_file', ['path' => 'a'])], self::APPROVE);

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertStringContainsString('malformed tool call', $verdict->findings[0]->text);
        self::assertStringContainsString('Tool \"write_file\" was not found', $this->sent[1]);
    }

    public function testAPathThatIsNotAStringEndsAsChangesEvenWhenTheModelApproves(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => 5])], self::APPROVE);

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertStringContainsString('malformed tool call', $verdict->findings[0]->text);
        self::assertStringContainsString('The path must be a string.', $this->sent[1]);
    }

    public function testASearchWithoutItsTextWhosePathEscapesTheCheckoutEndsAsChangesEvenWhenTheModelApproves(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'search_files', ['path' => '../outside'])], self::APPROVE);

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertStringContainsString('malformed tool call', $verdict->findings[0]->text);
        self::assertStringContainsString('Parameter \"text\" is mandatory for tool \"search_files\"', $this->sent[1]);
        self::assertStringNotContainsString('outside the repository', $this->sent[1]);
    }
}
