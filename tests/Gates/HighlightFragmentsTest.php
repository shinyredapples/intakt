<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragment;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightLayout;
use Intakt\Gates\HighlightRowKind;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightFragments::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightRowKind::class)]
#[CoversClass(HighlightFragment::class)]
#[CoversClass(HighlightLayout::class)]
final class HighlightFragmentsTest extends SandboxTestCase
{
    private const string LAYOUT = "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n\n## PHP 8.5 in use\n";

    private Sandbox $sandbox;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = $this->emptySandbox();
    }

    public function testRowsGoUnderTheTableHeaderAndItemsUnderTheirHeading(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n\n| Gates | done | T1 (#5) |\n\n## PHP 8.5 in use\n\n- The pipe operator: T6\n");

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertSame(
            "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n| Gates | done | T1 (#5) |\n\n## PHP 8.5 in use\n\n- The pipe operator: T6\n",
            $assembly->text,
        );
    }

    public function testFragmentsApplyInTheNaturalOrderOfTheirNumbers(): void
    {
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Later | planned | T10 (#10) |\n");
        $this->sandbox->write('docs/highlights.d/2-earlier.md', "## Tooling\n| Earlier | planned | T2 (#2) |\n");

        self::assertStringContainsString("| Earlier | planned | T2 (#2) |\n| Later | planned | T10 (#10) |\n", $this->assembled()->text);
    }

    public function testALaterRowWithTheSameFirstCellReplacesTheEarlierOneInPlace(): void
    {
        $this->sandbox->write('docs/highlights.d/1-first.md', "## Tooling\n| Gates | planned | T1 (#1) |\n| Clock | planned | T2 (#2) |\n");
        $this->sandbox->write('docs/highlights.d/3-update.md', "## Tooling\n|  Gates | done | T1 (#3) |\n");

        self::assertStringContainsString("|---|---|---|\n|  Gates | done | T1 (#3) |\n| Clock | planned | T2 (#2) |\n\n", $this->assembled()->text);
    }

    public function testASectionWithoutRowsKeepsItsHeader(): void
    {
        self::assertSame(
            "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n\n## PHP 8.5 in use\n",
            $this->assembled()->text,
        );
    }

    public function testASectionThatIsNotInTheLayoutFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tools\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:1: section "Tools" is not a section of docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testALineBeforeTheFirstSectionHeadingFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:1: the line is outside a section, start the fragment with a "## <section>" heading of docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testAListItemInATableSectionFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n- Gates\n");

        self::assertSame(['docs/highlights.d/1-gates.md:2: section "Tooling" holds table rows, each starting with "|"'], $this->assembled()->findings);
    }

    public function testATableRowInAListSectionFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-pipe.md', "## PHP 8.5 in use\n| Pipe | done | T6 |\n");

        self::assertSame(['docs/highlights.d/1-pipe.md:2: section "PHP 8.5 in use" holds list items, each starting with "- "'], $this->assembled()->findings);
    }

    public function testATableHeaderInAFragmentFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n|---|---|---|\n");

        self::assertSame(['docs/highlights.d/1-gates.md:2: a fragment holds rows only, the table header is in docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testTheSameRowTwiceInOneFragmentFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n| Gates | planned | T1 (#5) |\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:3: row "Gates" appears twice in this fragment'], $this->assembled()->findings);
    }

    public function testAFileNotNamedAfterAnIssueFails(): void
    {
        $this->sandbox->write('docs/highlights.d/gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");
        $this->sandbox->write('docs/highlights.d/1-Gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        self::assertSame(
            [
                'docs/highlights.d/1-Gates.md: a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
                'docs/highlights.d/gates.md: a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
            ],
            $this->assembled()->findings,
        );
    }

    public function testAMissingLayoutFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['cannot read docs/highlights.d/layout.md'], new HighlightFragments()->assembled($this->sandbox->root)->findings);
    }

    public function testThePageIsTheAssemblyEvenWhenALocalCopyIsStale(): void
    {
        $this->sandbox->write('docs/highlights.md', $this->assembled()->text);
        $this->sandbox->write('docs/highlights.d/1045-new-feature.md', "## Tooling\n| New feature | done | #1045 |\n");

        self::assertStringContainsString('| New feature | done | #1045 |', (string) new HighlightFragments()->pageOn($this->sandbox->root));
    }

    public function testBrokenFragmentsReadAsAnEmptyPage(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tools\n| Gates | done | T1 (#5) |\n");
        $this->assembled();

        self::assertSame('', new HighlightFragments()->pageOn($this->sandbox->root));
    }

    public function testWithoutFragmentsThePageIsTheFileItself(): void
    {
        $this->sandbox->write('docs/highlights.md', 'anything');

        self::assertSame('anything', new HighlightFragments()->pageOn($this->sandbox->root));
    }

    public function testWithoutFragmentsOrAFileThePageIsEmpty(): void
    {
        self::assertSame('', new HighlightFragments()->pageOn($this->sandbox->root));
    }

    private function assembled(): HighlightAssembly
    {
        if (!is_file($this->sandbox->root.'/docs/highlights.d/layout.md')) {
            $this->sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        }

        return new HighlightFragments()->assembled($this->sandbox->root);
    }
}
