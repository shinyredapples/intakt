<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\UntrustedSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptCut::class)]
#[UsesClass(Fence::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(UntrustedSource::class)]
final class PromptCutTest extends TestCase
{
    public function testATextUnderTheCeilingPassesUnchangedWithNoNote(): void
    {
        $text = str_repeat('a', PromptCut::CEILING_BYTES);

        $fenced = $this->cut()->fenced(UntrustedSource::Diff, $text)->rendered();

        self::assertStringContainsString("\n".$text."\n<<<END ", $fenced);
        self::assertStringNotContainsString('Intakt cut', $fenced);
    }

    public function testATextAtTheCeilingIsNotCut(): void
    {
        self::assertFalse($this->cut()->cuts(str_repeat('a', PromptCut::CEILING_BYTES)));
    }

    public function testATextOneByteOverTheCeilingIsCut(): void
    {
        self::assertTrue($this->cut()->cuts(str_repeat('a', PromptCut::CEILING_BYTES + 1)));
    }

    public function testATextOverTheCeilingIsCutAndTheNoteNamesTheBytesLeftOut(): void
    {
        $text = str_repeat('q', PromptCut::CEILING_BYTES + 500);

        $fenced = $this->cut()->fenced(UntrustedSource::Diff, $text)->rendered();

        self::assertSame(PromptCut::CEILING_BYTES, substr_count($fenced, 'q'));
        self::assertStringContainsString(sprintf('%d of %d bytes are shown', PromptCut::CEILING_BYTES, PromptCut::CEILING_BYTES + 500), $fenced);
    }

    public function testTheNoteComesAfterTheClosingMarkerOfTheFence(): void
    {
        $fenced = $this->cut()->fenced(UntrustedSource::Task, str_repeat('a', PromptCut::CEILING_BYTES + 1))->rendered();

        self::assertMatchesRegularExpression('/<<<END fence-[0-9a-f]{16}>>>\n\nIntakt cut this text/', $fenced);
    }

    public function testACutTextIsTheFenceOfItsHeadWithTheCutNoteOutsideIt(): void
    {
        $text = str_repeat('a', PromptCut::CEILING_BYTES + 1);

        $fenced = $this->cut()->fenced(UntrustedSource::Task, $text);

        self::assertEquals(new Fence()->wrap(UntrustedSource::Task, str_repeat('a', PromptCut::CEILING_BYTES))->cut(PromptCut::CEILING_BYTES, PromptCut::CEILING_BYTES + 1), $fenced);
    }

    public function testATextUnderTheCeilingIsJustItsFence(): void
    {
        self::assertEquals(new Fence()->wrap(UntrustedSource::Task, 'Add a cart.'), $this->cut()->fenced(UntrustedSource::Task, 'Add a cart.'));
    }

    public function testACutNeverSplitsAMultibyteCharacter(): void
    {
        $text = str_repeat('é', PromptCut::CEILING_BYTES);

        $fenced = $this->cut()->fenced(UntrustedSource::Diff, $text)->rendered();

        self::assertTrue(mb_check_encoding($fenced, 'UTF-8'));
        self::assertSame(PromptCut::CEILING_BYTES / 2, mb_substr_count($fenced, 'é'));
    }

    private function cut(): PromptCut
    {
        return new PromptCut(new Fence());
    }
}
