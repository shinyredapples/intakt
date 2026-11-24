<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownPage::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownLineKind::class)]
#[UsesClass(MarkdownLineKinds::class)]
#[UsesClass(MarkdownListIndents::class)]
#[UsesClass(MarkdownDelimiterRow::class)]
final class MarkdownPageLinesTest extends TestCase
{
    public function testEachParagraphCarriesTheLineItStartsOn(): void
    {
        $page = new MarkdownPage('docs/a.md', ['# Title', '', 'First line', 'wrapped on.', '', '- An item', '', '> Quoted start', 'lazy end']);

        self::assertSame([3, 6, 8], array_map(static fn (MarkdownParagraph $paragraph): int => $paragraph->line, $page->paragraphs()));
    }
}
