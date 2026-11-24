<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\MarkdownParagraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownParagraph::class)]
final class MarkdownParagraphTest extends TestCase
{
    public function testTheKeyIsTheFirstSixteenHexCharactersOfTheSha256HashOfTheParagraph(): void
    {
        $paragraph = new MarkdownParagraph('A paragraph of prose.', 4);

        self::assertSame('f99aa4efdc8bdf01', $paragraph->key());
    }

    public function testParagraphsWithDifferentTextHaveDifferentKeys(): void
    {
        self::assertNotSame(new MarkdownParagraph('One text.', 2)->key(), new MarkdownParagraph('Another text.', 2)->key());
    }
}
