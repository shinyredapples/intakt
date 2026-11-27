<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use stdClass;

final readonly class BrokenFieldRules
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2?: string}>
     */
    public static function cases(): iterable
    {
        yield from self::enums();
        yield from self::required();
        yield from self::unknown();
        yield from self::types();
        yield from self::constraints();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function enums(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'enum: verdict is not a decision' => [$design->with('verdict', 'approved')->json(), '"approve", "changes", "blocked"'];
        yield 'enum: verdict differs in case' => [$design->with('verdict', 'Approve')->json(), '"approve", "changes", "blocked"'];
        yield 'enum: severity is not P1 to P3' => [$design->withFinding('severity', 'P4')->json(), '"P1", "P2", "P3"'];
        yield 'enum: fixable_by is neither builder nor owner' => [$design->withFinding('fixable_by', 'reviewer')->json(), '"builder", "owner"'];
        yield 'enum: kind is not one of the kinds' => [$design->withFinding('kind', 'style')->json(), '"security", "logic", "missing test", "tidy-up", "other"'];
        yield 'enum: kind differs in case' => [$design->withFinding('kind', 'Logic')->json(), '"security", "logic", "missing test", "tidy-up", "other"'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function required(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        foreach (['verdict', 'resolved', 'follow_ups'] as $key) {
            yield 'required: '.$key => [$design->without($key)->json(), '"$'.$key.'"'];
        }
        yield 'required: findings' => ['{"verdict": "approve", "resolved": [], "follow_ups": []}', '"$findings"'];
        foreach (['id', 'severity', 'file', 'line', 'symbol', 'text', 'fixable_by', 'repeat', 'requirement', 'evidence'] as $key) {
            yield 'required: finding '.$key => [$design->withoutFinding($key)->json(), '"$'.$key.'"'];
        }
        yield 'required: evidence probe' => [$design->withFinding('evidence', ['output' => '...'])->json(), '"$probe"'];
        yield 'required: evidence output' => [$design->withFinding('evidence', ['probe' => '...'])->json(), '"$output"'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function unknown(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'unknown: a verdict field' => [$design->with('summary', '...')->json(), '"summary" is unknown'];
        yield 'unknown: a finding field' => [$design->withFinding('note', '...')->json(), '"findings[0].note" is unknown'];
        yield 'unknown: merged ids, which only the act list sets' => [$design->withFinding('merged', ['F1'])->json(), '"findings[0].merged" is unknown'];
        yield 'unknown: the origin of a failure verdict, which only Intakt sets' => [$design->with('origin', 'intakt')->json(), '"origin" is unknown'];
        yield 'unknown: an evidence field' => [$design->withFinding('evidence', ['probe' => '...', 'output' => '...', 'note' => '...'])->json(), '"findings[0].evidence.note" is unknown'];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: string}>
     */
    private static function types(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'type: the verdict is a list' => ['[]', '"$verdict"'];
        // Two wordings, because Symfony 8.1 refuses a findings object at the constructor's parameter and 8.2 at the serializer's attribute.
        yield 'type: findings is an object' => [$design->with('findings', ['id' => 'F3'])->json(), '($findings)', '"findings" attribute'];
        yield 'type: findings is an empty object' => [$design->with('findings', new stdClass())->json(), '($findings)', '"findings" attribute'];
        yield 'type: resolved is an empty object' => [$design->with('resolved', new stdClass())->json(), '"resolved" attribute'];
        yield 'type: follow_ups is an empty object' => [$design->with('follow_ups', new stdClass())->json(), '"follow_ups" attribute'];
        yield 'type: a finding is a string' => [$design->with('findings', ['F3'])->json(), '"$id"'];
        yield 'type: resolved is a string' => [$design->with('resolved', 'F1')->json(), '"resolved" attribute'];
        yield 'type: a resolved id is a number' => [$design->with('resolved', [1])->json(), '"resolved" attribute'];
        yield 'type: follow_ups is a string' => [$design->with('follow_ups', '...')->json(), '"follow_ups" attribute'];
        yield 'type: a follow-up is a boolean' => [$design->with('follow_ups', [true])->json(), '"follow_ups" attribute'];
        yield 'type: id is a number' => [$design->withFinding('id', 3)->json(), '"id" attribute'];
        yield 'type: file is a number' => [$design->withFinding('file', 1)->json(), '"file" attribute'];
        yield 'type: line is a string' => [$design->withFinding('line', '12')->json(), '"line" attribute'];
        yield 'type: line is a fraction' => [$design->withFinding('line', 1.5)->json(), '"line" attribute'];
        yield 'type: symbol is a list' => [$design->withFinding('symbol', ['a'])->json(), '"symbol" attribute'];
        yield 'type: text is null' => [$design->withFinding('text', null)->json(), '"text" attribute'];
        yield 'type: repeat is a string' => [$design->withFinding('repeat', 'false')->json(), '"repeat" attribute'];
        yield 'type: requirement is a number' => [$design->withFinding('requirement', 1)->json(), '"requirement" attribute'];
        yield 'type: evidence is a string' => [$design->withFinding('evidence', 'probe.php')->json(), '"evidence" attribute for class "'.Finding::class.'" must be one of "'.Evidence::class.'"'];
        yield 'type: evidence probe is a number' => [$design->withFinding('evidence', ['probe' => 1, 'output' => '...'])->json(), '"probe" attribute'];
        yield 'type: evidence output is a number' => [$design->withFinding('evidence', ['probe' => '...', 'output' => 1])->json(), '"output" attribute'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function constraints(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'constraint: id is blank' => [$design->withFinding('id', '')->json(), 'findings[0].id: This value should not be blank.'];
        yield 'constraint: text is blank' => [$design->withFinding('text', '')->json(), 'findings[0].text: This value should not be blank.'];
        yield 'constraint: line is zero' => [$design->withFinding('line', 0)->json(), 'findings[0].line: This value should be greater than or equal to 1.'];
        yield 'constraint: evidence probe is blank' => [$design->withFinding('evidence', ['probe' => '', 'output' => '...'])->json(), 'findings[0].evidence.probe: This value should not be blank.'];
        // Constraints, not required keys, because a stored finding from before titles and kinds reads without them; the answer must still name both.
        yield 'constraint: title is blank' => [$design->withFinding('title', '')->json(), 'findings[0].title: This value should not be blank.'];
        yield 'constraint: title is missing' => [$design->withoutFinding('title')->json(), 'findings[0].title: This value should not be null.'];
        yield 'constraint: kind is missing' => [$design->withoutFinding('kind')->json(), 'findings[0].kind: This value should not be null.'];
        yield 'constraint: kind is null' => [$design->withFinding('kind', null)->json(), 'findings[0].kind: This value should not be null.'];
    }
}
