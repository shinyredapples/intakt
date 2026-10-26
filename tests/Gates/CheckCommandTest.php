<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\GateResult;
use Intakt\Gates\JsonNode;
use Intakt\Gates\Mode;
use Intakt\Gates\Workspace;

final class CheckCommandTest extends SandboxTestCase
{
    public function testEveryOutputLineIsAJsonObject(): void
    {
        $run = $this->sandboxWith('fully-qualified-name-in-code')->check('--only=cs');

        foreach ($run->lines() as $line) {
            self::assertInstanceOf(JsonNode::class, JsonNode::parse($line), $line);
        }
        self::assertSame(1, $run->exitCode());
        self::assertStringContainsString('"result":"fail","failed":["cs"]', $run->output());
    }

    public function testAnUnknownArgumentIsAUsageError(): void
    {
        $run = $this->emptySandbox()->check('--everything');

        self::assertSame(2, $run->exitCode());
        self::assertStringContainsString('unknown argument', $run->output());
    }

    public function testAnUnknownGateNameIsAUsageError(): void
    {
        self::assertSame(2, $this->emptySandbox()->check('--only=magic')->exitCode());
    }

    public function testAnUnknownSkippedGateNameIsAUsageError(): void
    {
        self::assertSame(2, $this->emptySandbox()->check('--skip=magic')->exitCode());
    }

    public function testSkipLeavesOutTheNamedGates(): void
    {
        $output = $this->emptySandbox()->check('--only=audit,secrets', '--skip=audit')->output();

        self::assertStringContainsString('"gate":"secrets"', $output);
        self::assertStringNotContainsString('"gate":"audit"', $output);
    }

    public function testAGateReportsAtMostTenFindingsAndCountsTheRest(): void
    {
        $findings = array_map(static fn (int $number): string => 'finding '.$number, range(1, 25));

        $line = JsonNode::parse(GateResult::withFindings('cs', $findings)->toJson());

        self::assertNotNull($line);
        self::assertCount(10, $line->get('findings')->items());
        self::assertSame('15', $line->get('omitted')->text());
    }

    public function testALongFindingIsShortened(): void
    {
        $line = JsonNode::parse(GateResult::failedBecause('cs', str_repeat('x', 1000))->toJson());

        self::assertNotNull($line);
        self::assertLessThanOrEqual(300, strlen($line->get('findings')->items()[0]->text()));
    }

    public function testAMissingToolIsAFailureNotAPass(): void
    {
        $sandbox = $this->sandboxWith('clean');
        $withoutTools = $this->emptySandbox();
        $workspace = new Workspace($sandbox->root, $withoutTools->root, Mode::Full, null);

        $result = (new CodingStandardsGate())->run($workspace);

        self::assertFalse($result->isPassing());
        self::assertStringContainsString('missing tool', $result->findings[0]);
    }

    public function testTheFastRunLeavesFilesUnchangedSinceTheBaseAlone(): void
    {
        $sandbox = $this->branchOffABaseWithABadFile();
        $sandbox->write('src/Other.php', $this->cleanSource());

        self::assertTrue($sandbox->check('--only=cs', '--base=main')->failed('cs'));
        self::assertTrue($sandbox->check('--only=cs', '--fast', '--base=main')->passed('cs'));
    }

    public function testTheFastRunChecksFilesChangedSinceTheBase(): void
    {
        $sandbox = $this->branchOffABaseWithABadFile();
        $sandbox->write('src/Report.php', str_replace('2026-01-01', '2026-02-02', (string) file_get_contents($sandbox->root.'/src/Report.php')));

        self::assertTrue($sandbox->check('--only=cs', '--fast', '--base=main')->failed('cs'));
    }

    private function branchOffABaseWithABadFile(): Sandbox
    {
        $sandbox = $this->sandboxWith('fully-qualified-name-in-code');
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    private function cleanSource(): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/clean/src/Core/Report/Domain/Report.php.fixture');
    }
}
