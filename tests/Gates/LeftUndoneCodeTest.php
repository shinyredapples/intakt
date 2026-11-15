<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LeftUndoneCode;
use Intakt\Gates\LeftUndoneTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeftUndoneCode::class)]
#[CoversClass(LeftUndoneTokens::class)]
final class LeftUndoneCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notImplementedThrows(): iterable
    {
        yield 'a named message argument' => ["throw new LogicException(message: 'Not implemented');"];
        yield 'a block comment between throw and new' => ["throw /* why: pending */ new LogicException('Not implemented');"];
        yield 'a line comment between new and the class' => ["throw new // pending\n LogicException('Not implemented');"];
        yield 'an exception class named for it' => ['throw new NotImplementedException();'];
        yield 'a qualified class named for it without arguments' => ['throw new \\App\\UnimplementedFeature;'];
        yield 'a message built from a constant' => ["throw new LogicException(__METHOD__.' is not implemented');"];
        yield 'an interpolated message' => ['throw new LogicException("$method not implemented");'];
        yield 'a formatted message' => ["throw new LogicException(sprintf('%s is not yet implemented', \$name));"];
        yield 'a message on the next line' => ["throw new LogicException(\n    'Not implemented',\n);"];
    }

    #[DataProvider('notImplementedThrows')]
    public function testAnAddedNotImplementedThrowIsReportedOnTheLineOfItsThrow(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('src/Order.php', "<?php\n".$code, range(2, 4));

        self::assertStringStartsWith('not-implemented throw left in the change', $findings[2] ?? '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function throwsThatAreNotAboutMissingWork(): iterable
    {
        yield 'a named message about something else' => ["throw new LogicException(message: 'Order is empty');"];
        yield 'a class that merely holds the word implemented' => ["throw new ImplementedTwiceException('Order is empty');"];
        yield 'a message about another sentence' => ["throw new LogicException('Refunds are implemented by the forge');"];
        yield 'a reasoned exception on the throw line' => ["throw new LogicException( // TODO(#123): the forge sends no total yet\n    'Not implemented',\n);"];
    }

    #[DataProvider('throwsThatAreNotAboutMissingWork')]
    public function testAThrowThatIsNotLeftUndoneWorkIsNotReported(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('src/Order.php', "<?php\n".$code, range(2, 4));

        self::assertSame([], $findings);
    }

    public function testAChangeToTheMessageOfAnUnchangedThrowLineReportsTheThrow(): void
    {
        $findings = new LeftUndoneCode()->findingsOn('src/Order.php', "<?php\nthrow new LogicException(\n    'Not implemented',\n);", [3]);

        self::assertArrayHasKey(2, $findings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipsInAnotherLetterCase(): iterable
    {
        yield 'an upper-case method' => ['$this->MARKTESTSKIPPED();'];
        yield 'a mixed-case static call' => ['self::MarkTestIncomplete();'];
        yield 'a nullsafe call' => ['$this?->markTestSkipped();'];
        yield 'a lower-case attribute' => ['#[skip]'];
    }

    #[DataProvider('skipsInAnotherLetterCase')]
    public function testAnAddedSkipIsReportedWhateverItsLetterCase(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', "<?php\n".$code, [2]);

        self::assertStringContainsString('without a reason', $findings[2] ?? '');
    }

    public function testASkipAddedToAnExistingAttributeGroupIsReportedOnItsOwnLine(): void
    {
        $source = "<?php\n#[Test,\n    Skip\n]\nfunction testOrder() {}\n";

        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', $source, [3]);

        self::assertStringStartsWith('Skip attribute without a reason', $findings[3] ?? '');
    }

    public function testAnUnchangedSkipInAnAttributeGroupThatGainsAnotherAttributeIsNotReported(): void
    {
        $source = "<?php\n#[Test,\n    Skip\n]\nfunction testOrder() {}\n";

        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', $source, [2]);

        self::assertSame([], $findings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipsWithAReason(): iterable
    {
        yield 'a named message' => ["\$this->markTestSkipped(message: 'needs the docker socket');"];
        yield 'an interpolated message' => ['$this->markTestSkipped("needs $tool on the PATH");'];
        yield 'a formatted message' => ["\$this->markTestSkipped(sprintf('needs %s', \$tool));"];
        yield 'a double-quoted message' => ['self::markTestIncomplete("the forge sends no total yet");'];
        yield 'an attribute with a named reason' => ["#[Skip(reason: 'needs the docker socket')]"];
        yield 'a string that goes on after the reason on the line' => ["\$this->markTestSkipped('needs the docker socket\n');"];
    }

    #[DataProvider('skipsWithAReason')]
    public function testASkipWithAReasonOnItsLineIsNotReported(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', "<?php\n".$code, [2]);

        self::assertSame([], $findings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipsWithoutAReasonOnTheirLine(): iterable
    {
        yield 'a named variable message' => ['$this->markTestSkipped(message: $why);'];
        yield 'an interpolation without words' => ['$this->markTestSkipped("$why");'];
        yield 'a reason on the next line' => ["\$this->markTestSkipped(\n    'needs the docker socket'\n);"];
        yield 'an attribute reason on the next line' => ["#[Skip(\n    'needs the docker socket'\n)]"];
        yield 'a string that opens on the line with its reason on the next' => ["\$this->markTestSkipped('\nneeds a missing tool');"];
        yield 'an interpolated string with its reason on the next line' => ["\$this->markTestSkipped(\"\n\$tool is missing\");"];
        yield 'a heredoc reason' => ["\$this->markTestSkipped(<<<TEXT\nneeds the docker socket\nTEXT);"];
        yield 'a call after a comment' => ['$this->markTestSkipped /* why */ ();'];
        yield 'a call in braces inside a string' => ['$text = "{$this->markTestSkipped()}";'];
    }

    #[DataProvider('skipsWithoutAReasonOnTheirLine')]
    public function testASkipWithoutAReasonOnItsLineIsReported(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', "<?php\n".$code, range(2, 4));

        self::assertStringContainsString('without a reason; pass a message naming the missing tool or outside condition on the same line', $findings[2] ?? '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipNamesThatCallNothing(): iterable
    {
        yield 'a property in a double-quoted string' => ['$text = "$object->markTestSkipped";'];
        yield 'the call text in a double-quoted string' => ['$code = "$this->markTestSkipped();";'];
        yield 'the call text in a heredoc' => ["\$code = <<<TEXT\n\$this->markTestSkipped();\nTEXT;"];
        yield 'a parenthesis alone between two interpolations' => ['$code = "$this->markTestSkipped($why)";'];
        yield 'a parenthesis alone in a heredoc' => ["\$code = <<<TEXT\n\$this->markTestSkipped(\$why);\nTEXT;"];
        yield 'a property read' => ['$flag = $this->markTestSkipped;'];
        yield 'a nullsafe property read' => ['$flag = $this?->markTestSkipped;'];
        yield 'a class constant' => ['$name = self::markTestIncomplete;'];
    }

    #[DataProvider('skipNamesThatCallNothing')]
    public function testASkipNameWithoutACallIsNotReported(string $code): void
    {
        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', "<?php\n".$code, range(2, 4));

        self::assertSame([], $findings);
    }

    public function testAChangeInsideTheArgumentsOfAnUnchangedSkipLineReportsTheSkip(): void
    {
        $findings = new LeftUndoneCode()->findingsOn('tests/OrderTest.php', "<?php\n\$this->markTestSkipped(\n    \$why\n);", [3]);

        self::assertArrayHasKey(2, $findings);
    }
}
