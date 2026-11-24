<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsLimits;
use Intakt\Gates\DocsListGrowth;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\MarkdownCodeBlocks;
use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\MarkdownReader;
use Intakt\Gates\Mode;
use Intakt\Gates\UnneededDocsEntries;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocsGate::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(UnneededDocsEntries::class)]
#[UsesClass(DocsLimits::class)]
#[UsesClass(DocsListGrowth::class)]
#[UsesClass(MarkdownReader::class)]
#[UsesClass(MarkdownCodeBlocks::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(MarkdownPage::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownLineKind::class)]
#[UsesClass(MarkdownLineKinds::class)]
#[UsesClass(MarkdownListIndents::class)]
#[UsesClass(MarkdownDelimiterRow::class)]
#[UsesClass(Workspace::class)]
final class DocsGateRepositoryTest extends TestCase
{
    public function testTheRepositoryDocumentationMeetsTheLimitsWithItsList(): void
    {
        $root = dirname(__DIR__, 2);
        $gate = new DocsGate(new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents())));

        $result = $gate->run(new Workspace($root, $root, Mode::Full, null));

        self::assertSame([], $result->findings);
    }
}
