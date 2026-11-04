<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

#[CoversClass(VerdictParser::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(StrictVerdictSubscriber::class)]
#[CoversClass(StrictVerdictConverter::class)]
#[UsesClass(Kernel::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
final class VerdictParserTest extends KernelTestCase
{
    public function testTheDesignsExampleVerdictMapsToTheObjects(): void
    {
        $output = VerdictDocument::fromTheDesign()->json();

        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        $finding = new Finding(
            id: 'F3',
            severity: Severity::P1,
            file: '...',
            line: 12,
            symbol: '...',
            text: '...',
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: '...',
            evidence: new Evidence('...', '...'),
        );
        self::assertEquals(new Verdict(Decision::Changes, [$finding], ['F1', 'F2'], ['...']), $verdict);
    }

    public function testEveryDecisionMaps(): void
    {
        foreach (Decision::cases() as $decision) {
            $output = VerdictDocument::fromTheDesign()->with('verdict', $decision->value)->json();

            self::assertSame($decision, new VerdictParser()->parse($this->reviewer()->answering($output))->verdict);
        }
    }

    public function testEverySeverityMaps(): void
    {
        foreach (Severity::cases() as $severity) {
            $output = VerdictDocument::fromTheDesign()->withFinding('severity', $severity->value)->json();

            self::assertSame($severity, new VerdictParser()->parse($this->reviewer()->answering($output))->findings[0]->severity);
        }
    }

    public function testEveryFixableByMaps(): void
    {
        foreach (FixableBy::cases() as $fixableBy) {
            $output = VerdictDocument::fromTheDesign()->withFinding('fixable_by', $fixableBy->value)->json();

            self::assertSame($fixableBy, new VerdictParser()->parse($this->reviewer()->answering($output))->findings[0]->fixable_by);
        }
    }

    public function testARepeatedFindingMaps(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('repeat', true)->json();

        self::assertTrue(new VerdictParser()->parse($this->reviewer()->answering($output))->findings[0]->repeat);
    }

    public function testAFindingWithoutPlaceOrRequirementOrEvidenceMaps(): void
    {
        $output = VerdictDocument::fromTheDesign()
            ->withFinding('file', null)
            ->withFinding('line', null)
            ->withFinding('symbol', null)
            ->withFinding('requirement', null)
            ->withFinding('evidence', null)
            ->json();

        $finding = new VerdictParser()->parse($this->reviewer()->answering($output))->findings[0];

        self::assertEquals([null, null, null, null, null], [$finding->file, $finding->line, $finding->symbol, $finding->requirement, $finding->evidence]);
    }

    public function testAnApprovalWithoutFindingsMaps(): void
    {
        $output = '{"verdict": "approve", "findings": [], "resolved": [], "follow_ups": []}';

        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        self::assertEquals(new Verdict(Decision::Approve, [], [], []), $verdict);
    }

    #[DataProviderExternal(BrokenFieldRules::class, 'cases')]
    public function testABrokenFieldRuleYieldsChangesSayingWhy(string $output, string $why): void
    {
        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertStringContainsString($why, $verdict->findings[0]->text);
    }

    public function testAWholeFloatLineYieldsChanges(): void
    {
        $output = str_replace('"line":12', '"line":12.0', VerdictDocument::fromTheDesign()->json());

        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        self::assertStringContainsString('"line" attribute', $verdict->findings[0]->text);
    }

    #[DataProvider('outputsThatDoNotMap')]
    public function testOutputThatDoesNotMapYieldsChanges(string $output): void
    {
        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertStringEndsWith('Syntax error', $verdict->findings[0]->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outputsThatDoNotMap(): iterable
    {
        $json = VerdictDocument::fromTheDesign()->with('verdict', 'approve')->json();

        yield 'invalid JSON' => ['{"verdict": "approve", "findings": [],}'];
        yield 'prose' => ['The change looks good to me. Verdict: approve.'];
        yield 'a fenced block with prose around it' => ["Here is my verdict:\n```json\n".$json."\n```\nThanks."];
        yield 'a fenced block that is not JSON' => ["```json\nverdict: approve\n```"];
        yield 'prose around JSON' => ['Here is my verdict: '.$json];
        yield 'a lone fenced block around a valid verdict' => ["```json\n".$json."\n```"];
        yield 'empty output' => [''];
    }

    #[DataProviderExternal(AcceptedVerdicts::class, 'cases')]
    public function testAnAcceptedVerdictMapsWithoutAnInvalidOutputFinding(string $output): void
    {
        $verdict = new VerdictParser()->parse($this->reviewer()->answering($output));

        self::assertNotContains(Verdict::INVALID_OUTPUT, array_map(static fn (Finding $finding): string => $finding->id, $verdict->findings));
    }

    public function testAToolCallInsteadOfAVerdictYieldsChanges(): void
    {
        $verdict = new VerdictParser()->parse($this->reviewer()->answering(new ToolCallResult([new ToolCall('call-1', 'read_file')])));

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    public function testAReplyInSeveralPartsYieldsChanges(): void
    {
        $parts = new MultiPartResult([new TextResult(VerdictDocument::fromTheDesign()->with('verdict', 'approve')->json())]);

        $verdict = new VerdictParser()->parse($this->reviewer()->answering($parts));

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    public function testAFailedCallStaysAnError(): void
    {
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RateLimitExceededException());

        $this->expectException(RateLimitExceededException::class);

        $_ = new VerdictParser()->parse(new DeferredResult($converter, new InMemoryRawResult()));
    }

    public function testTheInvalidOutputFindingBlocksTheBuilderAndCitesTheRule(): void
    {
        $finding = new VerdictParser()->parse($this->reviewer()->answering('approve'))->findings[0];

        self::assertSame(Severity::P1, $finding->severity);
        self::assertSame(FixableBy::Builder, $finding->fixable_by);
        self::assertSame([null, null, null, null], [$finding->file, $finding->line, $finding->symbol, $finding->evidence]);
        self::assertSame('The verdict is a typed object that structured output fills and the Validator checks; anything else counts as changes.', $finding->requirement);
    }

    private function reviewer(): ScriptedReviewer
    {
        return ScriptedReviewer::in(self::getContainer());
    }
}
