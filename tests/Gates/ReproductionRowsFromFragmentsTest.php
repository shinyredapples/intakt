<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckedReports;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragment;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightLayout;
use Intakt\Gates\HighlightRowKind;
use Intakt\Gates\ReproductionRowsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ReproductionRowsGate::class)]
#[UsesClass(CheckedReports::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightFragments::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightRowKind::class)]
#[UsesClass(HighlightFragment::class)]
#[UsesClass(HighlightLayout::class)]
final class ReproductionRowsFromFragmentsTest extends SandboxTestCase
{
    private const string LAYOUT = "# Highlights\n\n## Found in the beta\n\n| Bug | Status | Where |\n|---|---|---|\n";

    public function testARowInAFragmentListsTheReproductionWithoutAPage(): void
    {
        $sandbox = $this->sandboxWithAReproduction();
        $sandbox->write('docs/highlights.d/7-lost-message.md', "## Found in the beta\n| Lost message | done | `upstream/messenger-lost-message/` |\n");

        $run = $sandbox->check('--only=reproductions');

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testARowOnlyInAStaleLocalPageDoesNotCount(): void
    {
        $sandbox = $this->sandboxWithAReproduction();
        $sandbox->write('docs/highlights.md', self::LAYOUT."| Lost message | done | `upstream/messenger-lost-message/` |\n");

        $run = $sandbox->check('--only=reproductions');

        self::assertTrue($run->failed('reproductions'), $run->output());
        self::assertStringContainsString('upstream/messenger-lost-message/ has no row', $run->findingsOf('reproductions'));
    }

    private function sandboxWithAReproduction(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        $sandbox->write('upstream/messenger-lost-message/README.md', "# Lost message\n");

        return $sandbox;
    }
}
