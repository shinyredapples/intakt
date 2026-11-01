<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TypeText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeText::class)]
final class TypeTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typesAndRemainders(): iterable
    {
        yield 'a plain type alone' => ['int', ''];
        yield 'a plain type and a name' => ['int $count', '$count'];
        yield 'a generic with a space inside' => ['array<int, string> $map', '$map'];
        yield 'a shape over several lines' => ["array{\n    id: int\n} \$row", '$row'];
        yield 'a union with spaces' => ['int | string $value', '$value'];
        yield 'an intersection with spaces' => ['Countable & Traversable $items', '$items'];
        yield 'a callable with a spaced return type' => ['Closure(int): bool $fn', '$fn'];
        yield 'a callable with a tight return type' => ['callable(string):int', ''];
        yield 'surrounding whitespace' => ['  int   the total  ', 'the total'];
    }

    #[DataProvider('typesAndRemainders')]
    public function testTheRemainderIsWhatFollowsTheType(string $text, string $remainder): void
    {
        self::assertSame($remainder, new TypeText()->remainderAfterType($text));
    }

    public function testTextWithoutATypeHasNoRemainder(): void
    {
        self::assertNull(new TypeText()->remainderAfterType('   '));
    }
}
