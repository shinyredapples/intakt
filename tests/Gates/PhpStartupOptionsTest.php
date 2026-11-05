<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStartupOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpStartupOptions::class)]
final class PhpStartupOptionsTest extends TestCase
{
    public function testTheOptionsBetweenTheBinaryAndTheScriptAreKept(): void
    {
        $options = PhpStartupOptions::between(['php', '-d', 'zend.assertions=-1', '-n', 'bin/check', '--fast'], ['bin/check', '--fast']);

        self::assertSame(['-d', 'zend.assertions=-1', '-n'], $options);
    }

    public function testACommandLineWithoutOptionsKeepsNone(): void
    {
        self::assertSame([], PhpStartupOptions::between(['php', 'bin/check', '--fast'], ['bin/check', '--fast']));
    }

    public function testAnOptionValueThatLooksLikeTheScriptIsStillAnOption(): void
    {
        $options = PhpStartupOptions::between(['php', '-c', 'bin/check', 'bin/check'], ['bin/check']);

        self::assertSame(['-c', 'bin/check'], $options);
    }

    public function testACommandLineThatDoesNotEndWithTheArgumentsKeepsNone(): void
    {
        self::assertSame([], PhpStartupOptions::between(['php', '-d', 'a=1', 'other', '--fast'], ['bin/check', '--fast']));
    }

    public function testACommandLineShorterThanTheArgumentsKeepsNone(): void
    {
        self::assertSame([], PhpStartupOptions::between(['bin/check'], ['bin/check', '--fast']));
    }
}
