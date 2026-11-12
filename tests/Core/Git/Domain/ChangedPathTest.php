<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Domain;

use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ChangeNotRead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangedPath::class)]
#[CoversClass(ChangeNotRead::class)]
final class ChangedPathTest extends TestCase
{
    private const string BLOB = '0123456789abcdef0123456789abcdef01234567';

    private const string NONE = '0000000000000000000000000000000000000000';

    public function testAnEmptyRawDiffChangesNothing(): void
    {
        self::assertSame([], ChangedPath::listFrom(''));
    }

    public function testEachRecordOfARawDiffIsAChangedPath(): void
    {
        $raw = ':100644 100644 '.self::BLOB.' '.self::BLOB." M\0bin/check\0:000000 100755 ".self::NONE.' '.self::BLOB." A\0a b\nc\0";

        self::assertEquals([new ChangedPath('bin/check', submodule: false), new ChangedPath("a b\nc", submodule: false)], ChangedPath::listFrom($raw));
    }

    #[DataProvider('submoduleModes')]
    public function testARecordWithTheSubmoduleModeOnEitherSideIsASubmodule(string $modes): void
    {
        $raw = ':'.$modes.' '.self::BLOB.' '.self::BLOB." T\0lib\0";

        self::assertEquals([new ChangedPath('lib', submodule: true)], ChangedPath::listFrom($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function submoduleModes(): iterable
    {
        yield 'added' => ['000000 160000'];
        yield 'removed' => ['160000 000000'];
        yield 'replaced by a file' => ['160000 100644'];
    }

    #[DataProvider('malformedRecords')]
    public function testARawDiffThatCannotBeReadIsRefused(string $raw): void
    {
        $this->expectException(ChangeNotRead::class);
        $this->expectExceptionMessage('the change could not be read: git printed a raw diff record that is not one');

        ChangedPath::listFrom($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedRecords(): iterable
    {
        yield 'no ending NUL' => [':100644 100644 '.self::BLOB.' '.self::BLOB." M\0bin/check"];
        yield 'no path' => [':100644 100644 '.self::BLOB.' '.self::BLOB." M\0"];
        yield 'an empty path' => [':100644 100644 '.self::BLOB.' '.self::BLOB." M\0\0"];
        yield 'a rename with two paths' => [':100644 100644 '.self::BLOB.' '.self::BLOB." R100\0a\0b\0"];
        yield 'a status line that is no record' => ["bin/check\0a\0"];
        yield 'a short mode' => [':10064 100644 '.self::BLOB.' '.self::BLOB." M\0bin/check\0"];
    }
}
