<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\ReviewerMemory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerMemory::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Fence::class)]
#[UsesClass(UntrustedSource::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(Paths::class)]
#[UsesClass(RelevantMemory::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(PromptAssembly::class)]
final class ReviewerPromptWiringTest extends KernelTestCase
{
    public function testTheContainerWiresTheReviewerPromptWithProjectMemory(): void
    {
        $memory = self::createStub(ProjectMemory::class);
        $memory->method('relevantTo')->willReturn(RelevantMemory::within([new MemoryEntry('alpha', 'Cart totals were rounded twice.', new Paths('src/Cart.php'))], 1_000));
        self::getContainer()->set(ProjectMemory::class, $memory);
        $prompt = self::getContainer()->get(ReviewerPrompt::class);
        $diff = "diff --git a/src/Cart.php b/src/Cart.php\n--- a/src/Cart.php\n+++ b/src/Cart.php\n";

        $messages = $prompt->messagesFor(new ReviewerConversation('You review.', new UuidV7()), new ReviewBrief(new ChangeUnderReview('alpha', 'Task.', $diff), [], 1))->getMessages();

        $last = array_last($messages);
        self::assertCount(4, $messages);
        self::assertInstanceOf(UserMessage::class, $last);
        self::assertStringContainsString('<<<UNTRUSTED project memory fence-', (string) $last->asText());
    }
}
