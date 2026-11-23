<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingKind;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
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
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[CoversClass(VerdictParser::class)]
#[CoversClass(VerdictUnreadable::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(StrictVerdictSubscriber::class)]
#[CoversClass(StrictVerdictConverter::class)]
#[CoversClass(ReviewerAnswer::class)]
#[UsesClass(Kernel::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
final class VerdictParserTest extends KernelTestCase
{
    public function testTheDesignsExampleVerdictMapsToTheObjects(): void
    {
        $output = VerdictDocument::fromTheDesign()->json();

        $verdict = $this->reviewer()->verdictFor($output);

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
            title: 'A cart loses its discount',
            kind: FindingKind::Logic,
        );
        self::assertEquals(new Verdict(Decision::Changes, [$finding], [new ResolvedFinding('F1'), new ResolvedFinding('F2')], [new FollowUpNote('...')]), $verdict);
    }

    public function testEveryDecisionMaps(): void
    {
        foreach (Decision::cases() as $decision) {
            $output = VerdictDocument::fromTheDesign()->with('verdict', $decision->value)->json();

            self::assertSame($decision, $this->reviewer()->verdictFor($output)->verdict);
        }
    }

    public function testEverySeverityMaps(): void
    {
        foreach (Severity::cases() as $severity) {
            $output = VerdictDocument::fromTheDesign()->withFinding('severity', $severity->value)->json();

            self::assertSame($severity, $this->reviewer()->verdictFor($output)->findings[0]->severity);
        }
    }

    public function testEveryFixableByMaps(): void
    {
        foreach (FixableBy::cases() as $fixableBy) {
            $output = VerdictDocument::fromTheDesign()->withFinding('fixable_by', $fixableBy->value)->json();

            self::assertSame($fixableBy, $this->reviewer()->verdictFor($output)->findings[0]->fixable_by);
        }
    }

    public function testARepeatedFindingMaps(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('repeat', true)->json();

        self::assertTrue($this->reviewer()->verdictFor($output)->findings[0]->repeat);
    }

    public function testAFindingTheReviewerMarksAsAGateGapMapsWithTheMarker(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('gate_gap', true)->json();

        self::assertTrue($this->reviewer()->verdictFor($output)->findings[0]->gate_gap);
    }

    public function testAFindingWithoutTheGateGapMarkerMapsAsNotAGateGap(): void
    {
        $output = VerdictDocument::fromTheDesign()->withoutFinding('gate_gap')->json();

        self::assertFalse($this->reviewer()->verdictFor($output)->findings[0]->gate_gap);
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

        $finding = $this->reviewer()->verdictFor($output)->findings[0];

        self::assertEquals([null, null, null, null, null], [$finding->file, $finding->line, $finding->symbol, $finding->requirement, $finding->evidence]);
    }

    public function testAnApprovalWithoutFindingsMaps(): void
    {
        $output = '{"verdict": "approve", "findings": [], "resolved": [], "follow_ups": []}';

        $verdict = $this->reviewer()->verdictFor($output);

        self::assertEquals(new Verdict(Decision::Approve, [], [], []), $verdict);
    }

    #[DataProviderExternal(BrokenFieldRules::class, 'cases')]
    public function testABrokenFieldRuleIsRefusedAsUnreadableSayingWhy(string $output, string $why, string ...$otherWordings): void
    {
        $wordings = array_map(static fn (string $wording): string => preg_quote($wording, '/'), [$why, ...$otherWordings]);

        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/'.implode('|', $wordings).'/');

        $this->reviewer()->verdictFor($output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function titlesOutsideThreeToSixWords(): iterable
    {
        yield 'two words' => ['Discount lost'];
        yield 'seven words' => ['A cart of one hundred loses discounts'];
    }

    // Only the classes refuse these, because Symfony AI describes a word count in the description alone, which the schema file cannot hold an answer to.
    #[DataProvider('titlesOutsideThreeToSixWords')]
    public function testATitleOutsideThreeToSixWordsIsRefusedAsUnreadable(string $title): void
    {
        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/findings\[0\]\.title: This value is too (short|long)/');

        $this->reviewer()->verdictFor(VerdictDocument::fromTheDesign()->withFinding('title', $title)->json());
    }

    public function testANullTitleIsRefusedAsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/findings\[0\]\.title: This value should not be null\./');

        $this->reviewer()->verdictFor(VerdictDocument::fromTheDesign()->withFinding('title', null)->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function titlesOfThreeToSixWords(): iterable
    {
        yield 'three words' => ['Discount is lost'];
        yield 'six words' => ['A cart of 100 loses discounts'];
    }

    #[DataProvider('titlesOfThreeToSixWords')]
    public function testATitleOfThreeToSixWordsMapsAsTheReviewerGaveIt(string $title): void
    {
        $verdict = $this->reviewer()->verdictFor(VerdictDocument::fromTheDesign()->withFinding('title', $title)->json());

        self::assertSame($title, $verdict->findings[0]->title);
    }

    /**
     * @return iterable<string, array{string, FindingKind}>
     */
    public static function kinds(): iterable
    {
        foreach (FindingKind::cases() as $kind) {
            yield $kind->value => [$kind->value, $kind];
        }
    }

    #[DataProvider('kinds')]
    public function testEveryKindMaps(string $value, FindingKind $kind): void
    {
        $verdict = $this->reviewer()->verdictFor(VerdictDocument::fromTheDesign()->withFinding('kind', $value)->json());

        self::assertSame($kind, $verdict->findings[0]->kind);
    }

    public function testAWrongTypeIsRefusedWithTheContractVerdictNamedInItsText(): void
    {
        $output = '{"verdict":"approve","findings":[],"resolved":false,"follow_ups":[]}';
        $before = '';
        try {
            self::getContainer()->get('serializer')->deserialize($output, ReviewerAnswer::class, JsonEncoder::FORMAT, [
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
                JsonDecode::ASSOCIATIVE => false,
            ]);
        } catch (ExceptionInterface $refused) {
            $before = sprintf('Cannot deserialize the content into the "%s" class. %s', Verdict::class, strtr($refused->getMessage(), [ReviewerAnswer::class => Verdict::class]));
        }

        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($before, '/').'$/');

        $this->reviewer()->verdictFor($output);
    }

    public function testAWholeFloatLineIsRefusedAsUnreadable(): void
    {
        $output = str_replace('"line":12', '"line":12.0', VerdictDocument::fromTheDesign()->json());

        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/"line" attribute/');

        $this->reviewer()->verdictFor($output);
    }

    #[DataProvider('outputsThatDoNotMap')]
    public function testOutputThatDoesNotMapIsRefusedAsUnreadable(string $output): void
    {
        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/Syntax error$/');

        $this->reviewer()->verdictFor($output);
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
        $verdict = $this->reviewer()->verdictFor($output);

        self::assertNotContains(Verdict::INVALID_OUTPUT, array_map(static fn (Finding $finding): string => $finding->id, $verdict->findings));
    }

    public function testAToolCallInsteadOfAVerdictIsRefusedAsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->reviewer()->verdictFor(new ToolCallResult([new ToolCall('call-1', 'read_file')]));
    }

    public function testAReplyInSeveralPartsIsRefusedAsUnreadable(): void
    {
        $answer = VerdictDocument::fromTheDesign()->with('verdict', 'approve')->json();

        $this->expectException(VerdictUnreadable::class);

        $this->reviewer()->verdictFor(new MultiPartResult([new TextResult($answer), new TextResult($answer)]));
    }

    public function testAFailedCallStaysAnError(): void
    {
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RateLimitExceededException());

        $this->expectException(RateLimitExceededException::class);

        $result = new DeferredResult($converter, new InMemoryRawResult());

        $_ = new VerdictParser()->parseWith(static fn (): Verdict => $result->asObject(ReviewerAnswer::class)->toVerdict());
    }

    public function testABrokenConstraintGivesItsPathAndIntaktsMessageAsAKeySafeReason(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('line', 0)->json();

        self::assertSame('the reviewer\'s answer could not be read as a verdict: findings[0].line: This value should be greater than or equal to 1.', $this->refusalOf($output)->keySafeInWords());
    }

    public function testABlankValueGivesItsPathAsAKeySafeReason(): void
    {
        $output = VerdictDocument::fromTheDesign()->withFinding('evidence', ['probe' => '', 'output' => '...'])->json();

        self::assertSame('the reviewer\'s answer could not be read as a verdict: findings[0].evidence.probe: This value should not be blank.', $this->refusalOf($output)->keySafeInWords());
    }

    public function testAnAnswerThatDoesNotMapHasNoKeySafeReason(): void
    {
        self::assertNull($this->refusalOf('{"verdict": "approve", "findings": [],}')->keySafeInWords());
    }

    public function testAnUnknownFieldNamedByTheModelHasNoKeySafeReason(): void
    {
        self::assertNull($this->refusalOf(VerdictDocument::fromTheDesign()->with('summary', '...')->json())->keySafeInWords());
    }

    public function testTheRefusalSaysTheAnswerCouldNotBeReadAsAVerdict(): void
    {
        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/^The reviewer\'s answer could not be read as a verdict: /');

        $this->reviewer()->verdictFor('approve');
    }

    private function refusalOf(string $output): VerdictUnreadable
    {
        try {
            $_ = $this->reviewer()->verdictFor($output);
        } catch (VerdictUnreadable $unreadable) {
            return $unreadable;
        }

        self::fail('The answer was read as a verdict.');
    }

    private function reviewer(): ScriptedReviewer
    {
        return ScriptedReviewer::in(self::getContainer());
    }
}
