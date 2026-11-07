<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightRowKind;
use Intakt\Gates\HighlightsGate;
use Intakt\Gates\HighlightStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightsGate::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightStatus::class)]
#[UsesClass(HighlightFragments::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightRowKind::class)]
final class HighlightsAssemblyGateTest extends SandboxTestCase
{
    private const string LAYOUT = "# Highlights\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n";

    private const string ASSEMBLED = self::LAYOUT."| Gates | done | T1 (#5) |\n";

    public function testAnAssembledFileThatMatchesItsFragmentsPasses(): void
    {
        $run = $this->checkWithAssembled(self::ASSEMBLED);

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAHandEditOfTheAssembledFileFails(): void
    {
        $run = $this->checkWithAssembled(self::ASSEMBLED."| Clock | done | T2 (#6) |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md is not the assembly of docs/highlights.d/', $run->findingsOf('highlights'));
    }

    public function testABrokenFragmentFailsWithItsLine(): void
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->write('docs/highlights.d/7-clock.md', "## Clocks\n| Clock | done | T2 (#6) |\n");
        $sandbox->write('docs/highlights.md', self::ASSEMBLED);

        $run = $sandbox->check('--only=highlights');

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.d/7-clock.md:1: section "Clocks"', $run->findingsOf('highlights'));
    }

    public function testFragmentsWithoutAnAssembledFileFail(): void
    {
        $run = $this->sandboxWithFragments()->check('--only=highlights');

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md is not the assembly of docs/highlights.d/', $run->findingsOf('highlights'));
    }

    private function checkWithAssembled(string $assembled): CheckRun
    {
        $sandbox = $this->sandboxWithFragments();
        $sandbox->write('docs/highlights.md', $assembled);

        return $sandbox->check('--only=highlights');
    }

    private function sandboxWithFragments(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        $sandbox->write('docs/highlights.d/5-gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        return $sandbox;
    }
}
