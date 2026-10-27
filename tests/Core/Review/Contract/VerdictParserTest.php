<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Place;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Triage;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerdictParser::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(Place::class)]
#[UsesClass(Triage::class)]
final class VerdictParserTest extends TestCase
{
    public function testTheDesignsExampleVerdictParses(): void
    {
        $output = VerdictDocument::fromTheDesign()->json();

        $verdict = new VerdictParser()->parse($output);

        $finding = new Finding('F3', '...', new Triage(Severity::P1, FixableBy::Builder, repeat: false))
            ->at(new Place('...', 12, '...'))
            ->citing('...')
            ->provenBy(new Evidence('...', '...'));
        self::assertEquals(new Verdict(Decision::Changes, [$finding])->resolving(['F1', 'F2'])->followingUp(['...']), $verdict);
    }

    public function testARepeatedFindingParses(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('repeat', true)->json();

        self::assertTrue(new VerdictParser()->parse($output)->findings[0]->triage->repeat);
    }

    public function testEveryDecisionParses(): void
    {
        foreach (Decision::cases() as $decision) {
            $output = VerdictDocument::fromTheDesign()->with('verdict', $decision->value)->json();

            self::assertSame($decision, new VerdictParser()->parse($output)->decision);
        }
    }

    public function testEverySeverityParses(): void
    {
        foreach (Severity::cases() as $severity) {
            $output = VerdictDocument::fromTheDesign()->withFinding('severity', $severity->value)->json();

            self::assertSame($severity, new VerdictParser()->parse($output)->findings[0]->triage->severity);
        }
    }

    public function testEveryFixableByParses(): void
    {
        foreach (FixableBy::cases() as $fixableBy) {
            $output = VerdictDocument::fromTheDesign()->withFinding('fixable_by', $fixableBy->value)->json();

            self::assertSame($fixableBy, new VerdictParser()->parse($output)->findings[0]->triage->fixableBy);
        }
    }

    public function testAFindingWithoutPlaceOrRequirementOrEvidenceParses(): void
    {
        $output = VerdictDocument::fromTheDesign()
            ->withFinding('file', null)
            ->withFinding('line', null)
            ->withFinding('symbol', null)
            ->withFinding('requirement', null)
            ->withFinding('evidence', null)
            ->json();

        $finding = new VerdictParser()->parse($output)->findings[0];

        self::assertEquals(new Finding('F3', '...', new Triage(Severity::P1, FixableBy::Builder, repeat: false)), $finding);
    }

    public function testAnApprovalWithoutFindingsParses(): void
    {
        $output = '{"verdict": "approve", "findings": [], "resolved": [], "follow_ups": []}';

        $verdict = new VerdictParser()->parse($output);

        self::assertEquals(new Verdict(Decision::Approve, []), $verdict);
    }

    #[DataProvider('brokenFieldRules')]
    public function testABrokenFieldRuleYieldsChangesNamingTheField(string $output, string $path): void
    {
        $verdict = new VerdictParser()->parse($output);

        self::assertSame(Decision::Changes, $verdict->decision);
        self::assertStringContainsString(': '.$path.': ', $verdict->findings[0]->text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenFieldRules(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'enum: verdict is not a decision' => [$design->with('verdict', 'approved')->json(), '/verdict'];
        yield 'enum: verdict differs in case' => [$design->with('verdict', 'Approve')->json(), '/verdict'];
        yield 'enum: severity is not P1 to P3' => [$design->withFinding('severity', 'P4')->json(), '/findings/0/severity'];
        yield 'enum: fixable_by is neither builder nor owner' => [$design->withFinding('fixable_by', 'reviewer')->json(), '/findings/0/fixable_by'];

        foreach (['verdict', 'resolved', 'follow_ups'] as $key) {
            yield 'required: '.$key => [$design->without($key)->json(), '/'.$key];
        }
        yield 'required: findings' => ['{"verdict": "approve", "resolved": [], "follow_ups": []}', '/findings'];
        foreach (['id', 'severity', 'file', 'line', 'symbol', 'text', 'fixable_by', 'repeat', 'requirement', 'evidence'] as $key) {
            yield 'required: finding '.$key => [$design->withoutFinding($key)->json(), '/findings/0/'.$key];
        }
        yield 'required: evidence probe' => [$design->withFinding('evidence', ['output' => '...'])->json(), '/findings/0/evidence/probe'];
        yield 'required: evidence output' => [$design->withFinding('evidence', ['probe' => '...'])->json(), '/findings/0/evidence/output'];

        yield 'type: findings is an object' => [$design->with('findings', ['id' => 'F3'])->json(), '/findings'];
        yield 'type: a finding is a string' => [$design->with('findings', ['F3'])->json(), '/findings/0'];
        yield 'type: resolved is a string' => [$design->with('resolved', 'F1')->json(), '/resolved'];
        yield 'type: a resolved id is a number' => [$design->with('resolved', [1])->json(), '/resolved/0'];
        yield 'type: follow_ups is a string' => [$design->with('follow_ups', '...')->json(), '/follow_ups'];
        yield 'type: a follow-up is a boolean' => [$design->with('follow_ups', [true])->json(), '/follow_ups/0'];
        yield 'type: id is a number' => [$design->withFinding('id', 3)->json(), '/findings/0/id'];
        yield 'type: file is a number' => [$design->withFinding('file', 1)->json(), '/findings/0/file'];
        yield 'type: line is a string' => [$design->withFinding('line', '12')->json(), '/findings/0/line'];
        yield 'type: line is a fraction' => [$design->withFinding('line', 1.5)->json(), '/findings/0/line'];
        yield 'type: symbol is a list' => [$design->withFinding('symbol', ['a'])->json(), '/findings/0/symbol'];
        yield 'type: text is null' => [$design->withFinding('text', null)->json(), '/findings/0/text'];
        yield 'type: repeat is a string' => [$design->withFinding('repeat', 'false')->json(), '/findings/0/repeat'];
        yield 'type: requirement is a number' => [$design->withFinding('requirement', 1)->json(), '/findings/0/requirement'];
        yield 'type: evidence is a string' => [$design->withFinding('evidence', 'probe.php')->json(), '/findings/0/evidence'];
        yield 'type: evidence probe is a number' => [$design->withFinding('evidence', ['probe' => 1, 'output' => '...'])->json(), '/findings/0/evidence/probe'];
        yield 'type: evidence output is a number' => [$design->withFinding('evidence', ['probe' => '...', 'output' => 1])->json(), '/findings/0/evidence/output'];
        yield 'type: the verdict is a list' => ['[]', '/'];

        yield 'additional: an unknown verdict field' => [$design->with('summary', 'fine')->json(), '/summary'];
        yield 'additional: an unknown finding field' => [$design->withFinding('title', 'x')->json(), '/findings/0/title'];
        yield 'additional: an unknown evidence field' => [$design->withFinding('evidence', ['probe' => '...', 'output' => '...', 'exit' => 0])->json(), '/findings/0/evidence/exit'];
    }

    #[DataProvider('outputsThatAreNotJson')]
    public function testOutputThatIsNotJsonYieldsChanges(string $output): void
    {
        $verdict = new VerdictParser()->parse($output);

        self::assertEquals(Verdict::invalidOutput('it is not JSON (Syntax error)'), $verdict);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outputsThatAreNotJson(): iterable
    {
        $json = VerdictDocument::fromTheDesign()->with('verdict', 'approve')->json();

        yield 'invalid JSON' => ['{"verdict": "approve", "findings": [],}'];
        yield 'prose' => ['The change looks good to me. Verdict: approve.'];
        yield 'a fenced block' => ["```json\n".$json."\n```"];
        yield 'prose around JSON' => ['Here is my verdict: '.$json];
        yield 'empty output' => [''];
    }

    #[DataProvider('linesBeyondAnInteger')]
    public function testALineJsonSchemaCountsAsIntegerButPhpDecodesAsFloatYieldsChanges(string $line): void
    {
        $output = str_replace('"line":12', '"line":'.$line, VerdictDocument::fromTheDesign()->json());

        $verdict = new VerdictParser()->parse($output);

        self::assertEquals(Verdict::invalidOutput('a finding line is not a whole number in range'), $verdict);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linesBeyondAnInteger(): iterable
    {
        yield 'a whole float' => ['12.0'];
        yield 'beyond the integer range' => ['1e30'];
    }

    public function testTheInvalidOutputFindingBlocksAndCitesTheRule(): void
    {
        $finding = new VerdictParser()->parse('approve')->findings[0];

        self::assertSame(Verdict::INVALID_OUTPUT, $finding->id);
        self::assertSame(Severity::P1, $finding->triage->severity);
        self::assertSame(FixableBy::Builder, $finding->triage->fixableBy);
        self::assertEquals(new Place(), $finding->place);
        self::assertSame('The verdict is JSON and is validated against a schema; anything else counts as changes.', $finding->requirement);
    }
}
