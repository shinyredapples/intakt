<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommentRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommentRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedComments(): iterable
    {
        yield 'a type docblock' => ["/**\n * @param list<string> \$names\n * @return array<int, string>\n */"];
        yield 'an array shape over several lines' => ["/**\n * @param array{\n *     id: int,\n *     name: string\n * } \$row\n */"];
        yield 'a union type with spaces' => ["/**\n * @return int | string\n */"];
        yield 'a template bound' => ["/**\n * @template T of object\n */"];
        yield 'a tool specific type tag' => ["/**\n * @phpstan-type Row array{id: int}\n */"];
        yield 'a variable type' => ['/** @var list<int> $ids */'];
        yield 'a one-line why with a link' => ['// The provider rounds half down: https://example.test/docs/rounding'];
        yield 'a one-line why with a reason' => ['// Sorted first because the forge returns pages in any order'];
        yield 'a throws tag' => ["/**\n * @throws RuntimeException\n */"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedComments(): iterable
    {
        yield 'a block comment' => ['/* why: https://example.test */', 'block comment'];
        yield 'a line comment that says what' => ['// Calculates the total', 'does not say why'];
        yield 'a hash comment that says what' => ['# Loop over the findings', 'does not say why'];
        yield 'a todo with a link' => ['// TODO https://example.test/issues/1', 'TODO'];
        yield 'a fixme' => ['// fixme later', 'TODO'];
        yield 'commented-out code' => ['// $total = 1;', 'commented-out'];
        yield 'a docblock with prose' => ["/**\n * Calculates the total.\n */", 'prose'];
        yield 'prose after a param type' => ["/**\n * @param int \$count the number of items\n */", 'prose'];
        yield 'a return with prose' => ["/**\n * @return int the total\n */", 'prose'];
        yield 'an inheritdoc' => ["/**\n * {@inheritDoc}\n */", 'prose'];
        yield 'a throws tag with prose' => ["/**\n * @throws RuntimeException when the file is missing\n */", 'prose'];
        yield 'an empty docblock' => ["/**\n */", 'empty'];
        yield 'a suppression comment' => ['// @phpstan-ignore-next-line', 'suppression'];
        yield 'a suppression comment with a link' => ['// @phpstan-ignore-next-line https://example.test/docs', 'suppression'];
        yield 'a psalm suppression with a link' => ['// @psalm-suppress MixedAssignment https://example.test/docs', 'suppression'];
        yield 'a coding standards suppression' => ['// phpcs:ignore https://example.test/docs', 'suppression'];
        yield 'a suppression tag in a docblock' => ["/**\n * @phpstan-ignore-next-line\n */", 'suppression'];
        yield 'a psalm suppression tag in a docblock' => ["/**\n * @psalm-suppress MixedAssignment\n */", 'suppression'];
        yield 'a no-rector tag in a docblock' => ["/**\n * @noRector\n */", 'suppression'];
        yield 'a no-rector comment with a link' => ['// @noRector https://example.test/docs', 'suppression'];
        yield 'a no-inspection tag in a docblock' => ["/**\n * @noinspection PhpUnused\n */", 'suppression'];
        yield 'a tool tag that is not a type tag' => ["/**\n * @phpstan-ignore-line\n */", 'suppression'];
        yield 'an unknown tool tag' => ["/**\n * @phpstan-whatever\n */", 'not a type tag'];
    }

    #[DataProvider('allowedComments')]
    public function testAllowedFormsPass(string $comment): void
    {
        self::assertSame([], new CommentRule()->violations($this->codeWith($comment)));
    }

    #[DataProvider('rejectedComments')]
    public function testOtherFormsAreRejected(string $comment, string $expectedProblem): void
    {
        $violations = new CommentRule()->violations($this->codeWith($comment));

        self::assertCount(1, $violations);
        self::assertStringContainsString($expectedProblem, $violations[0]);
    }

    public function testAWhyLongerThanOneLineIsRejected(): void
    {
        $violations = new CommentRule()->violations($this->codeWith("// First line https://example.test\n// second line https://example.test"));

        self::assertCount(1, $violations);
        self::assertStringContainsString('longer than one line', $violations[0]);
    }

    public function testViolationsNameTheLine(): void
    {
        $violations = new CommentRule()->violations($this->codeWith('// Adds one'));

        self::assertStringStartsWith('line 3:', $violations[0]);
    }

    private function codeWith(string $comment): string
    {
        return "<?php\n\n".$comment."\nfinal class Sample\n{\n}\n";
    }
}
