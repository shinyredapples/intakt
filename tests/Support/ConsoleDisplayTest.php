<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(ConsoleDisplay::class)]
final class ConsoleDisplayTest extends TestCase
{
    public function testAPathThatAnErrorBlockWrapsIsWholeAgain(): void
    {
        $path = '/'.str_repeat('deep-folder/', 20).'broken.json';
        $output = new BufferedOutput();

        new SymfonyStyle(new ArrayInput([]), $output)->error('Cannot read '.$path.'.');

        $display = $output->fetch();
        self::assertStringNotContainsString($path, $display);
        self::assertStringContainsString($path.'.', ConsoleDisplay::unwrapped($display));
    }

    public function testADisplayWithoutLineBreaksStaysTheSame(): void
    {
        self::assertSame('one line  with spaces', ConsoleDisplay::unwrapped('one line  with spaces'));
    }
}
