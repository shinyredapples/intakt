<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\QuestionRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildQuestion::class)]
#[CoversClass(QuestionRefused::class)]
final class BuildQuestionTest extends TestCase
{
    public function testAQuestionWithChoicesAndARecommendationAmongThemIsAccepted(): void
    {
        $question = BuildQuestion::fromHandedBack('{"question":" Which store? ","choices":["SQLite","Postgres"],"recommendation":"SQLite"}');

        self::assertSame(['Which store?', ['SQLite', 'Postgres'], 'SQLite'], [$question->question, $question->choices, $question->recommendation]);
    }

    #[TestWith(['{"\u0000note":1,"question":"Which store?","choices":["SQLite"],"recommendation":"SQLite"}'])]
    #[TestWith(['{"question":"Which store?","choices":["SQLite"],"recommendation":"SQLite","\u0000":{"\u0000deep":["x"]}}'])]
    #[TestWith(["{\"question\":\"Which store?\",\"choices\":[\"SQLite\"],\"recommendation\":\"SQLite\",\"\\u0000note\" \t\r\n: null}"])]
    #[TestWith(['{"note":7,"":"empty key","question":"Which store?","choices":["SQLite"],"recommendation":"SQLite"}'])]
    public function testAnUnknownKeyIsIgnoredAlsoWhenItStartsWithNul(string $text): void
    {
        $question = BuildQuestion::fromHandedBack($text);

        self::assertSame(['Which store?', ['SQLite'], 'SQLite'], [$question->question, $question->choices, $question->recommendation]);
    }

    public function testATextThatLooksLikeAKeyStartingWithNulInsideAValueIsKeptAsItIs(): void
    {
        $question = BuildQuestion::fromHandedBack('{"question":"Is \"\u0000x\": a key?","choices":["\\\\","no"],"recommendation":"\\\\"}');

        self::assertSame(["Is \"\0x\": a key?", ['\\', 'no'], '\\'], [$question->question, $question->choices, $question->recommendation]);
    }

    #[TestWith(['{"\u0000note":1,"question":"Q","choices":{"0":"a"},"recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":[{"\u0000":"a"}],"recommendation":"a"}', 'a choice is not a non-empty text'])]
    #[TestWith(['{"\u0000question":"Q","choices":["a"],"recommendation":"a"}', 'the question text is empty'])]
    #[TestWith(['{"\u0000note":1,"question":"Q","choices":["a"],"recommendation":"a"', 'the question is not a JSON object'])]
    #[TestWith(['{"question":"Q","choices":["Keep"," Keep "],"recommendation":"Keep"}', 'two choices are the same'])]
    #[TestWith(['{"question":"Q","choices":["a","b","a"],"recommendation":"b"}', 'two choices are the same'])]
    #[TestWith(['{"question":"Q","choices":["a","b"],"recommendation":"c"}', 'the recommendation is not one of the choices'])]
    #[TestWith(['{"question":"Q","choices":["a"]}', 'the recommendation is not one of the choices'])]
    #[TestWith(['{"question":"Q","choices":[],"recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":{"x":"a"},"recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":{"0":"a"},"recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":{"0":"a","1":"b"},"recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":"a","recommendation":"a"}', 'the question has no list of choices'])]
    #[TestWith(['{"question":"Q","choices":[{"0":"a"}],"recommendation":"a"}', 'a choice is not a non-empty text'])]
    #[TestWith(['{"question":{"0":"Q"},"choices":["a"],"recommendation":"a"}', 'the question text is empty'])]
    #[TestWith(['{}', 'the question text is empty'])]
    #[TestWith(['{"question":["Q"],"choices":["a"],"recommendation":"a"}', 'the question text is empty'])]
    #[TestWith(['{"question":"Q","choices":["a"," "],"recommendation":"a"}', 'a choice is not a non-empty text'])]
    #[TestWith(['{"question":"Q","choices":["a",3],"recommendation":"a"}', 'a choice is not a non-empty text'])]
    #[TestWith(['{"question":" ","choices":["a"],"recommendation":"a"}', 'the question text is empty'])]
    #[TestWith(['not json', 'the question is not a JSON object'])]
    #[TestWith(['["a"]', 'the question is not a JSON object'])]
    #[TestWith(['"a"', 'the question is not a JSON object'])]
    public function testAQuestionThatBreaksTheRuleIsRefusedWithItsReason(string $text, string $reason): void
    {
        $this->expectExceptionObject(new QuestionRefused($reason));

        BuildQuestion::fromHandedBack($text);
    }

    public function testAQuestionLongerThanTheLimitIsRefused(): void
    {
        $text = json_encode(['question' => str_repeat('q', BuildQuestion::MAX_BYTES), 'choices' => ['a'], 'recommendation' => 'a']);

        $this->expectExceptionObject(QuestionRefused::tooLong(BuildQuestion::MAX_BYTES));

        BuildQuestion::fromHandedBack((string) $text);
    }

    public function testAQuestionExactlyAtTheLimitIsAccepted(): void
    {
        $base = (string) json_encode(['question' => '', 'choices' => ['a'], 'recommendation' => 'a']);
        $text = (string) json_encode(['question' => str_repeat('q', BuildQuestion::MAX_BYTES - strlen($base)), 'choices' => ['a'], 'recommendation' => 'a']);

        self::assertSame(BuildQuestion::MAX_BYTES, strlen($text));
        self::assertSame(['a'], BuildQuestion::fromHandedBack($text)->choices);
    }

    public function testAQuestionNestingEightArraysOrObjectsCountingItselfIsRefusedAlsoUnderAnIgnoredKey(): void
    {
        $this->expectExceptionObject(QuestionRefused::notJson());

        BuildQuestion::fromHandedBack('{"question":"Q","choices":["a"],"recommendation":"a","x":[[[[[[[]]]]]]]}');
    }

    public function testAQuestionNestingSevenArraysOrObjectsCountingItselfIsAccepted(): void
    {
        self::assertSame(['a'], BuildQuestion::fromHandedBack('{"question":"Q","choices":["a"],"recommendation":"a","x":[[[[[{"y":1}]]]]]}')->choices);
    }
}
