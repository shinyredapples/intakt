<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CommentRuleHelperOwnersTest extends TestCase
{
    #[DataProvider('helpers')]
    public function testEachCommentRuleHelperIsCoveredOnlyByTheTestClassNamedAfterIt(string $helper): void
    {
        $owners = array_values(array_filter(
            glob(__DIR__.'/*Test.php') ?: [],
            static fn (string $file): bool => str_contains((string) file_get_contents($file), '#[CoversClass('.$helper.'::class)]'),
        ));

        self::assertSame([__DIR__.'/'.$helper.'Test.php'], $owners);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function helpers(): iterable
    {
        yield 'the docblock rule' => ['DocblockRule'];
        yield 'the type text' => ['TypeText'];
    }
}
