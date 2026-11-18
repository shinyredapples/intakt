<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragment;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightLayout;
use Intakt\Gates\HighlightRowKind;
use Intakt\Gates\HighlightsGate;
use Intakt\Gates\HighlightStatus;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightsGate::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightStatus::class)]
#[UsesClass(HighlightFragments::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightRowKind::class)]
#[UsesClass(HighlightFragment::class)]
#[UsesClass(HighlightLayout::class)]
#[UsesClass(Workspace::class)]
final class HighlightsAssemblyGateTest extends SandboxTestCase
{
    private const string LAYOUT = "# Highlights\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n";

    public function testFragmentsWithoutAPagePass(): void
    {
        $run = $this->sandboxWithFragments()->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testALocalPageThatIsNotTheAssemblyFails(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->write('docs/highlights.md', self::LAYOUT."| Gates | done | T1 (#5) |\n");
        $sandbox->write('docs/highlights.d/1045-new-feature.md', "## Tooling\n| New feature | done | T2 (#1045) |\n");

        $run = $sandbox->check('--only=highlights');

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md is not the assembly of docs/highlights.d/', $run->findingsOf('highlights'));
    }

    public function testAHandEditedLocalPageFailsInARepository(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->startRepository();
        $sandbox->commitAll('docs: add the highlight fragments');
        $sandbox->write('docs/highlights.md', new HighlightFragments()->assembled($sandbox->root)->text."HAND EDIT\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md is not the assembly of docs/highlights.d/', $run->findingsOf('highlights'));
    }

    public function testALocalPageThatIsTheAssemblyPasses(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->startRepository();
        $sandbox->commitAll('docs: add the highlight fragments');
        $sandbox->write('docs/highlights.md', new HighlightFragments()->assembled($sandbox->root)->text);

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testACommittedPageFails(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->startRepository();
        $sandbox->write('docs/highlights.md', self::LAYOUT."| Gates | done | T1 (#5) |\n| Hand edit | done | T3 (#7) |\n");
        $sandbox->commitAll('docs: edit the highlights page by hand');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md is committed, but bin/highlights generates it', $run->findingsOf('highlights'));
    }

    public function testTheTableRulesApplyToTheAssembledRows(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->write('docs/highlights.d/7-clock.md', "## Tooling\n| Clock | finished | T2 (#6) |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md:8: status "finished"', $run->findingsOf('highlights'));
    }

    public function testABrokenFragmentFailsWithItsLine(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->write('docs/highlights.d/7-clock.md', "## Clocks\n| Clock | done | T2 (#6) |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.d/7-clock.md:1: section "Clocks"', $run->findingsOf('highlights'));
    }

    public function testTwoChangesThatEachAddARowMergeWithoutAConflictAndPass(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->startRepository();
        $sandbox->commitAll('docs: add the highlight fragments');
        $sandbox->git('checkout', '--quiet', '-b', 'first');
        $sandbox->write('docs/highlights.d/10-first.md', "## Tooling\n| First | done | T10 (#10) |\n");
        $sandbox->commitAll('docs: add the first highlight');
        $sandbox->git('checkout', '--quiet', 'main');
        $sandbox->git('checkout', '--quiet', '-b', 'second');
        $sandbox->write('docs/highlights.d/11-second.md', "## Tooling\n| Second | done | T11 (#11) |\n");
        $sandbox->commitAll('docs: add the second highlight');
        $sandbox->git('checkout', '--quiet', 'main');
        $sandbox->git('merge', '--quiet', '--no-edit', 'first');

        $merge = $sandbox->git('merge', '--quiet', '--no-edit', 'second');
        $run = $sandbox->runGate($this->gate());

        self::assertTrue($merge->succeeded(), $merge->output.$merge->errors);
        self::assertTrue($run->passed('highlights'), $run->output());
    }

    private function sandboxWithFragments(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        $sandbox->write('docs/highlights.d/5-gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        return $sandbox;
    }

    private function gate(): HighlightsGate
    {
        return new HighlightsGate(new FencedCodeBlocks(), new HighlightFragments());
    }
}
