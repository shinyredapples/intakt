<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(ConsoleDisplay::class)]
final class ConsoleDisplayTest extends TestCase
{
    public function testAPathThatAnErrorBlockWrapsIsFoundWhole(): void
    {
        $path = '/'.str_repeat('deep-folder/', 20).'broken.json';

        $display = $this->error('Cannot read '.$path.'.');

        self::assertStringNotContainsString($path, $display);
        self::assertMatchesRegularExpression(ConsoleDisplay::wrapped($path.'.'), $display);
    }

    public function testAPathWithSpacesThatAnErrorBlockWrapsIsFoundWhole(): void
    {
        $path = '/'.str_repeat('folder with spaces/', 15).'broken.json';

        $display = $this->error('Cannot read '.$path.'.');

        self::assertStringNotContainsString($path, $display);
        self::assertMatchesRegularExpression(ConsoleDisplay::wrapped($path.'.'), $display);
    }

    public function testASpaceInTheTextIsNotFoundWhereTheDisplayHasNone(): void
    {
        self::assertDoesNotMatchRegularExpression(ConsoleDisplay::wrapped('/a b/broken.json'), '/ab/broken.json');
    }

    public function testCharactersTheDisplaySeparatesOnOneLineAreNotFound(): void
    {
        self::assertDoesNotMatchRegularExpression(ConsoleDisplay::wrapped('/ab/broken.json'), '/a b/broken.json');
    }

    public function testPatternCharactersInTheTextAreMatchedLiterally(): void
    {
        self::assertMatchesRegularExpression(ConsoleDisplay::wrapped('/shadow[1]*?.json'), 'read /shadow[1]*?.json');
        self::assertDoesNotMatchRegularExpression(ConsoleDisplay::wrapped('/shadow[1]*?.json'), 'read /shadow1.json');
    }

    private function error(string $message): string
    {
        $output = new BufferedOutput();
        new SymfonyStyle(new ArrayInput([]), $output)->error($message);

        return $output->fetch();
    }
}
