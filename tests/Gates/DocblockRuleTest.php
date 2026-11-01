<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocblockRule;
use Intakt\Gates\SuppressionDirective;
use Intakt\Gates\TypeText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocblockRule::class)]
#[UsesClass(SuppressionDirective::class)]
#[UsesClass(TypeText::class)]
final class DocblockRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function typeOnlyDocblocks(): iterable
    {
        yield 'a parameter and a return type' => ["/**\n * @param list<string> \$names\n * @return array<int, string>\n */"];
        yield 'an array shape over several lines' => ["/**\n * @param array{\n *     id: int,\n *     name: string\n * } \$row\n */"];
        yield 'a variadic parameter' => ["/**\n * @param int ...\$counts\n */"];
        yield 'a variable type without a name' => ['/** @var list<int> */'];
        yield 'a template with a bound and a default' => ["/**\n * @template T of object = stdClass\n */"];
        yield 'a type alias' => ["/**\n * @phpstan-type Row array{id: int}\n */"];
        yield 'an imported type alias' => ["/**\n * @phpstan-import-type Row from Source as Line\n */"];
        yield 'an assertion' => ["/**\n * @phpstan-assert-if-true non-empty-string \$this->name\n */"];
        yield 'a magic method' => ["/**\n * @method static self named(string \$name)\n */"];
        yield 'a pure marker' => ["/**\n * @psalm-pure\n */"];
        yield 'a deprecation with its reason' => ["/**\n * @deprecated use Total instead\n */"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function docblocksWithAProblem(): iterable
    {
        yield 'prose before the tags' => ["/**\n * Calculates the total.\n * @return int\n */", 'docblock with prose, only type tags are allowed'];
        yield 'no tags at all' => ["/**\n */", 'empty docblock'];
        yield 'a suppression tag' => ["/**\n * @phpstan-ignore-next-line\n */", 'suppression directive, fix the code instead'];
        yield 'a tag that is not a type tag' => ["/**\n * @author Someone\n */", '@author is not a type tag'];
        yield 'prose after a parameter' => ["/**\n * @param int \$count the number of items\n */", '@param carries prose or lacks its type'];
        yield 'a parameter without its name' => ["/**\n * @param int\n */", '@param carries prose or lacks its type'];
        yield 'prose on a continuation line' => ["/**\n * @return int\n *     the total\n */", '@return carries prose or lacks its type'];
        yield 'an import without its source' => ["/**\n * @phpstan-import-type Row\n */", '@phpstan-import-type carries prose or lacks its type'];
        yield 'a marker with a body' => ["/**\n * @pure always\n */", '@pure carries prose or lacks its type'];
        yield 'a deprecation without a reason' => ["/**\n * @deprecated\n */", '@deprecated carries prose or lacks its type'];
    }

    #[DataProvider('typeOnlyDocblocks')]
    public function testADocblockOfTypeTagsHasNoProblem(string $docblock): void
    {
        self::assertNull(new DocblockRule()->problem($docblock));
    }

    #[DataProvider('docblocksWithAProblem')]
    public function testADocblockBeyondTypeTagsNamesItsProblem(string $docblock, string $expectedProblem): void
    {
        self::assertSame($expectedProblem, new DocblockRule()->problem($docblock));
    }

    public function testTheFirstProblemTagIsTheOneNamed(): void
    {
        self::assertSame('@see is not a type tag', new DocblockRule()->problem("/**\n * @return int\n * @see Other\n * @author Someone\n */"));
    }
}
