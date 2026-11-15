<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\AddedLines;
use Intakt\Gates\LeftUndoneCode;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\LeftUndoneGate;
use Intakt\Gates\Scope;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(AddedLines::class)]
#[CoversClass(LeftUndoneComments::class)]
#[CoversClass(LeftUndoneCode::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
final class LeftUndoneGateTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addedMarkerComments(): iterable
    {
        yield 'a TODO line comment' => ['    // TODO: handle the refund', 'TODO'];
        yield 'a FIXME line comment' => ['    // FIXME the total is off by one', 'FIXME'];
        yield 'an XXX line comment' => ['    // XXX breaks on empty orders', 'XXX'];
        yield 'a hash comment' => ['    # TODO handle the refund', 'TODO'];
        yield 'a lower-case marker' => ['    // todo: handle the refund', 'todo'];
        yield 'a block comment' => ['    /* FIXME the total */', 'FIXME'];
        yield 'a docblock' => ['    /** TODO handle the refund */', 'TODO'];
        yield 'a marker without a reason after its issue' => ['    // TODO(#12):', 'TODO'];
        yield 'a marker naming an issue without a hash' => ['    // TODO(12): handle the refund', 'TODO'];
        yield 'a marker naming an issue without a colon' => ['    // TODO(#12) handle the refund', 'TODO'];
    }

    #[DataProvider('addedMarkerComments')]
    public function testAnAddedMarkerCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('src/Order.php:5: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    public function testEachMarkerLineOfAMultiLineBlockCommentIsNamedByItsOwnLine(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', "<?php\n\n/*\n * The order\n * XXX breaks on empty orders\n */\nfinal class Order {}\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:5: XXX comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linesWithoutALeftUndoneComment(): iterable
    {
        yield 'a single-quoted string' => ["    public const string NOTE = '// TODO: handle the refund';"];
        yield 'a double-quoted string' => ['    public const string NOTE = "# FIXME the total";'];
        yield 'a heredoc' => ["    public const string NOTE = <<<TEXT\n    XXX breaks\n    // TODO later\n    TEXT;"];
        yield 'a marker later in the comment' => ['    // Kept apart from the TODO list because it is sorted'];
        yield 'a word that starts with a marker' => ['    // TODOS stay sorted because the view reads them in order'];
        yield 'an exception naming its issue and reason' => ['    // TODO(#123): drop once the forge sends the total, because it is computed twice'];
        yield 'a FIXME exception' => ['    // FIXME(#7): the rounding needs the new money type'];
    }

    #[DataProvider('linesWithoutALeftUndoneComment')]
    public function testALineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerCommentInAFileHoldingANulByteIsReported(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', "<?php\n/* TODO: finish work */\n\$binary = \"\0\";\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:2: TODO comment', $run->findingsOf('left-undone'));
    }

    public function testAMarkerCommentInAFileMarkedAsBinaryIsReported(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('.gitattributes', "*.php -diff\n");
        $sandbox->write('src/Order.php', "<?php\n/* TODO: finish work */\n");
        $sandbox->commitAll('feat: add a file');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:2: TODO comment', $run->findingsOf('left-undone'));
    }

    public function testAMarkerCommentInAFileWithCarriageReturnLineEndingsIsReported(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', "<?php\r/* TODO: finish work */\r// FIXME the total\r");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:1: TODO comment', $run->findingsOf('left-undone'));
    }

    public function testAMarkerCommentAfterCarriageReturnsKeepsTheLineGitNumbersIt(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', "<?php\r\n\r\n/*\r * The order\r * XXX breaks\r */\nfinal class Order {}\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:3: XXX comment', $run->findingsOf('left-undone'));
    }

    public function testAReasonedExceptionPassesTheWholeCheck(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine('    // TODO(#123): drop once the forge sends the total, because it is computed twice'));

        $run = $sandbox->check('--only=left-undone,comments');

        self::assertTrue($run->passed('left-undone'), $run->output());
        self::assertTrue($run->passed('comments'), $run->output());
    }

    public function testAMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    // TODO: handle the refund'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('src/Order.php', $this->classWithBodyLine("    // TODO: handle the refund\n    public const int LIMIT = 3;"));
        $sandbox->commitAll('feat: limit the orders');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAnUnchangedMarkerCommentBetweenJoinedHunksIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Order.php', $this->classWithBodyLine("    public const int LIMIT = 1;\n    // TODO: handle the refund\n    public const int FLOOR = 1;"));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->git('config', 'diff.interHunkContext', '5');
        $sandbox->write('src/Order.php', $this->classWithBodyLine("    public const int LIMIT = 2;\n    // TODO: handle the refund\n    public const int FLOOR = 2;"));
        $sandbox->commitAll('feat: change the limits');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerCommentInARenamedFileIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Order.php', $this->classWithBodyLine("    // TODO: handle the refund\n    public const int LIMIT = 3;\n    public const int FLOOR = 1;\n    public const int STEP = 2;"));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->git('mv', 'src/Order.php', 'src/Purchase.php');
        $sandbox->commitAll('refactor: rename the order');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerCommentInAFileNotYetCommittedFails(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    // TODO: handle the refund'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php:5: TODO comment', $run->findingsOf('left-undone'));
    }

    public function testAnExtensionlessPhpFileIsScanned(): void
    {
        $sandbox = $this->changeWithFile('bin/check', "#!/usr/bin/env php\n<?php\n\n// FIXME the exit code\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('bin/check:4: FIXME comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotScanned(): iterable
    {
        yield 'Markdown' => ['docs/guide.md'];
        yield 'a test fixture' => ['tests/Fixtures/Order.php'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/src/Order.php'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/src/Order.php'];
        yield 'an upstream reproduction' => ['upstream/serializer/Order.php'];
        yield 'a text file' => ['notes.txt'];
    }

    #[DataProvider('pathsThatAreNotScanned')]
    public function testAMarkerCommentOutsideThePhpScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->classWithBodyLine('    // TODO: handle the refund'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testADeletedFileIsNotRead(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    public const int LIMIT = 3;'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->git('rm', '--quiet', 'src/Order.php');
        $sandbox->commitAll('refactor: drop the order');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAChangedFileThatCannotBeReadFailsNamingIt(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine('    public const int LIMIT = 3;'));
        chmod($sandbox->root.'/src/Order.php', 0o000);

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('src/Order.php', $run->findingsOf('left-undone'));
    }

    public function testAnUntrackedFileThatCannotBeReadFailsNamingIt(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->startedChange();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    public const int LIMIT = 3;'));
        chmod($sandbox->root.'/src/Order.php', 0o000);

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('src/Order.php cannot be read', $run->findingsOf('left-undone'));
    }

    public function testTheBaseBranchItselfPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    // TODO: handle the refund'));
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testTheGateFailsWhenThereIsNoBaseBranch(): void
    {
        $sandbox = $this->startedChange();

        $run = $sandbox->check('--only=left-undone', '--base=missing');

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('no base branch found', $run->findingsOf('left-undone'));
    }

    public function testATreeWithoutHistoryIsSkipped(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Order.php', $this->classWithBodyLine('    // TODO: handle the refund'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
        self::assertStringContainsString('skipped, no git history', $run->noteOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skipsWithoutAReason(): iterable
    {
        yield 'a bare static skip' => ['        self::markTestSkipped();', 'markTestSkipped call'];
        yield 'a bare incomplete' => ['        $this->markTestIncomplete();', 'markTestIncomplete call'];
        yield 'a skip with an empty message' => ["        \$this->markTestSkipped('');", 'markTestSkipped call'];
        yield 'a skip with a variable message' => ['        $this->markTestSkipped($why);', 'markTestSkipped call'];
        yield 'a skip attribute' => ['    #[Skip]', 'Skip attribute'];
        yield 'a qualified skip attribute' => ['    #[\\PHPUnit\\Framework\\Attributes\\Skip]', '\\PHPUnit\\Framework\\Attributes\\Skip attribute'];
        yield 'a skip attribute after another' => ['    #[Test, SkipAlways]', 'SkipAlways attribute'];
        yield 'an incomplete attribute' => ['    #[IncompleteTest()]', 'IncompleteTest attribute'];
    }

    #[DataProvider('skipsWithoutAReason')]
    public function testAnAddedSkipWithoutAReasonFailsNamingTheFileTheLineAndHowToComply(string $line, string $kind): void
    {
        $sandbox = $this->changeWithFile('tests/OrderTest.php', "<?php\n\nfinal class OrderTest\n{\n".$line."\n}\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('tests/OrderTest.php:5: '.$kind.' without a reason; pass a message naming the missing tool or outside condition', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linesWithoutAnUndoneTestOrThrow(): iterable
    {
        yield 'a skip naming a missing tool' => ["        \$this->markTestSkipped('python3 is not on the PATH');"];
        yield 'a skip attribute with a reason' => ["    #[Skip('needs the docker socket')]"];
        yield 'a skip inside a string' => ["        \$code = '\$this->markTestSkipped();';"];
        yield 'a skip inside a heredoc' => ["        \$code = <<<TEXT\n        self::markTestIncomplete();\n        TEXT;"];
        yield 'a method merely named like a skip' => ['        $this->markTestSkippedBecause();'];
        yield 'an unrelated attribute' => ['    #[RequiresPhp(">=8.5")]'];
        yield 'a throw that is not about missing work' => ["        throw new LogicException('Order is empty');"];
    }

    #[DataProvider('linesWithoutAnUndoneTestOrThrow')]
    public function testALineWithoutAnUndoneTestOrThrowPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('tests/OrderTest.php', "<?php\n\nfinal class OrderTest\n{\n".$line."\n}\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notImplementedThrows(): iterable
    {
        yield 'a logic exception' => ["        throw new LogicException('Not implemented');"];
        yield 'not yet implemented' => ['        throw new RuntimeException("Not yet implemented");'];
        yield 'a fully qualified class' => ["        throw new \\BadMethodCallException('Method is not implemented');"];
        yield 'a throw with a comment that names no issue' => ["        throw new LogicException('Not implemented'); // finish later"];
    }

    #[DataProvider('notImplementedThrows')]
    public function testAnAddedNotImplementedThrowFailsNamingTheFileTheLineAndHowToComply(string $line): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('src/Order.php:5: not-implemented throw left in the change; implement it, or open an issue and add // TODO(#<issue>): <reason> on the same line', $run->findingsOf('left-undone'));
    }

    public function testANotImplementedThrowWithAReasonedExceptionOnTheSameLinePasses(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine("        throw new LogicException('Not implemented'); // TODO(#123): the forge sends no total yet"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testANotImplementedThrowInATestFileIsNotReported(): void
    {
        $sandbox = $this->changeWithFile('tests/OrderTest.php', $this->classWithBodyLine("        throw new LogicException('Not implemented');"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testASkipInASourceFileIsNotReported(): void
    {
        $sandbox = $this->changeWithFile('src/Order.php', $this->classWithBodyLine('        $this->markTestSkipped();'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testANotImplementedThrowUnderTheFixturesFolderIsNotScanned(): void
    {
        $sandbox = $this->changeWithFile('tests/Fixtures/Order.php', $this->classWithBodyLine("        throw new LogicException('Not implemented');"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testASkipAndAThrowThatExistedBeforeTheChangeAreNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('tests/OrderTest.php', $this->classWithBodyLine('        $this->markTestSkipped();'));
        $sandbox->write('src/Order.php', $this->classWithBodyLine("        throw new LogicException('Not implemented');"));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('docs: add a readme');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    private function classWithBodyLine(string $line): string
    {
        return "<?php\n\nfinal class Order\n{\n".$line."\n}\n";
    }

    private function changeWithFile(string $path, string $contents): Sandbox
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, $contents);
        $sandbox->commitAll('feat: add a file');

        return $sandbox;
    }

    private function startedChange(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }
}
