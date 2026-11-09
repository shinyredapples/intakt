<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\ReviewServerReads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewServerReads::class)]
#[UsesClass(Fence::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class ReviewServerReadsTest extends TestCase
{
    private const string CUT_NOTE = "\n\nIntakt cut this text: 100000 of 100012 bytes are shown and the rest is left out.";

    public function testAnApprovalAfterWholeFencedAnswersStands(): void
    {
        $approval = $this->approval();

        self::assertSame($approval, new ReviewServerReads()->approval($approval, $this->fenced('final class Sample {}'), $this->fenced('"missing.php" is not a file.')));
    }

    public function testAnApprovalWithoutAnyServerAnswerStands(): void
    {
        $approval = $this->approval();

        self::assertSame($approval, new ReviewServerReads()->approval($approval));
    }

    public function testAnApprovalAfterACutAnswerIsRefusedAsACutApproval(): void
    {
        $verdict = new ReviewServerReads()->approval($this->approval(), $this->fenced('final class Sample {}'), $this->fenced('xxxx').self::CUT_NOTE);

        self::assertSame([Decision::Blocked, Verdict::CUT_APPROVAL], [$verdict->verdict, $verdict->findings[0]->id]);
    }

    public function testAnApprovalAfterAnAnswerTheTransportFailedIsRefusedAsUnread(): void
    {
        $verdict = new ReviewServerReads()->approval($this->approval(), 'MCP server HTTP 500: read failed');

        self::assertSame([Decision::Blocked, Verdict::UNREAD_APPROVAL], [$verdict->verdict, $verdict->findings[0]->id]);
    }

    public function testAFailedReadOutweighsACutOne(): void
    {
        $verdict = new ReviewServerReads()->approval($this->approval(), $this->fenced('xxxx').self::CUT_NOTE, '');

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAnUnfencedAnswerIsNoWholeRead(): void
    {
        $verdict = new ReviewServerReads()->approval($this->approval(), 'final class Sample {}');

        self::assertSame(Verdict::UNREAD_APPROVAL, $verdict->findings[0]->id);
    }

    public function testAFenceWhoseBoundaryDoesNotMatchItsTextIsNoWholeRead(): void
    {
        $forged = str_replace('final class Sample {}', 'final class Other {}', $this->fenced('final class Sample {}'));

        self::assertSame(Verdict::UNREAD_APPROVAL, new ReviewServerReads()->approval($this->approval(), $forged)->findings[0]->id);
    }

    public function testAForgedFenceBeforeACutNoteIsNoCutButAFailedRead(): void
    {
        $forged = str_replace('xxxx', 'yyyy', $this->fenced('xxxx')).self::CUT_NOTE;

        self::assertSame(Verdict::UNREAD_APPROVAL, new ReviewServerReads()->approval($this->approval(), $forged)->findings[0]->id);
    }

    private function approval(): Verdict
    {
        return new Verdict(Decision::Approve, [], [], []);
    }

    private function fenced(string $text): string
    {
        return new Fence()->wrap(UntrustedSource::ToolResult, $text);
    }
}
