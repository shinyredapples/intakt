<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ShellHereDocuments;
use Intakt\Gates\ShellLexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShellLexer::class)]
#[UsesClass(ShellHereDocuments::class)]
final class ShellFunctionBodyTest extends TestCase
{
    public function testATodoAfterAFunctionBodyThatShiftsInArithmeticIsReturned(): void
    {
        $comments = new ShellLexer("f() { (( x <<= 1 )); }\n# TODO later\n")->comments();

        self::assertSame([[23, '# TODO later']], $comments);
    }

    public function testATodoAfterAFunctionBodyOpenedOnTheNextLineIsReturned(): void
    {
        $comments = new ShellLexer("f()\n{ (( x <<= 1 )); }\n# TODO later\n")->comments();

        self::assertSame([[23, '# TODO later']], $comments);
    }

    public function testATodoAfterAFunctionDeclaredWithTheKeywordAndParenthesesIsReturned(): void
    {
        $comments = new ShellLexer("function f () { (( y <<= 2 )); }\n# TODO later\n")->comments();

        self::assertSame([[33, '# TODO later']], $comments);
    }

    public function testACaseRightAfterTheBraceOfAFunctionBodyKeepsItsPatternsFromClosingASubstitution(): void
    {
        $script = "echo \"\$(f() { case \$1 in a) echo \"it's\" ;; esac; }; f a)\"\n# TODO later\necho \"done\"\n";

        $comments = new ShellLexer($script)->comments();

        self::assertSame([[58, '# TODO later']], $comments);
    }

    public function testAnEmptyArrayAssignmentStartsNoCommand(): void
    {
        $script = "echo \"\$(list=() case \"it's\" )\"\n# TODO later\n";

        $comments = new ShellLexer($script)->comments();

        self::assertSame([[31, '# TODO later']], $comments);
    }

    public function testArithmeticAfterTimeWithAnOptionIsStillReadAsArithmetic(): void
    {
        $comments = new ShellLexer("time -p (( x <<= 1 ))\n# TODO later\n")->comments();

        self::assertSame([[22, '# TODO later']], $comments);
    }
}
