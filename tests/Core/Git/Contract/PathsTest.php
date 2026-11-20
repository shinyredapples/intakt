<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Paths::class)]
final class PathsTest extends TestCase
{
    public function testThePathsIterateInTheOrderTheyWereGiven(): void
    {
        $paths = new Paths('src/B.php', 'src/A.php');

        self::assertSame(['src/B.php', 'src/A.php'], [...$paths]);
    }

    public function testAPathGivenTwiceIsKeptTwice(): void
    {
        $paths = new Paths('src/A.php', 'src/A.php');

        self::assertSame(['src/A.php', 'src/A.php'], [...$paths]);
    }

    public function testPathsSpreadFromAStringKeyedArrayIterateAsAList(): void
    {
        $paths = new Paths(...['first' => 'src/A.php', 'second' => 'src/B.php']);

        self::assertSame(['src/A.php', 'src/B.php'], [...$paths]);
    }

    public function testPathsWithoutAnyPathAreEmpty(): void
    {
        self::assertTrue(new Paths()->isEmpty());
    }

    public function testPathsWithAPathAreNotEmpty(): void
    {
        self::assertFalse(new Paths('src/A.php')->isEmpty());
    }

    public function testPathsShareAnyWithPathsThatHoldOneOfTheirPaths(): void
    {
        $paths = new Paths('src/A.php', 'src/B.php');

        self::assertTrue($paths->sharesAnyWith(new Paths('src/B.php', 'src/C.php')));
    }

    public function testPathsShareNothingWithPathsThatHoldNoneOfTheirPaths(): void
    {
        $paths = new Paths('src/A.php');

        self::assertFalse($paths->sharesAnyWith(new Paths('src/B.php')));
    }

    public function testPathsShareNothingWithEmptyPaths(): void
    {
        self::assertFalse(new Paths('src/A.php')->sharesAnyWith(new Paths()));
    }

    public function testAPathMatchesOnlyItsExactSpelling(): void
    {
        self::assertFalse(new Paths('src/A.php')->sharesAnyWith(new Paths('src/a.php', './src/A.php')));
    }
}
