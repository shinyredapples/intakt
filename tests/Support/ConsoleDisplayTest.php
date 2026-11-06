<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use InvalidArgumentException;
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
        self::assertThat($display, ConsoleDisplay::shows($path.'.'));
    }

    public function testAPathWithSpacesThatAnErrorBlockWrapsIsFoundWhole(): void
    {
        $path = '/'.str_repeat('folder with spaces/', 15).'broken.json';

        $display = $this->error('Cannot read '.$path.'.');

        self::assertStringNotContainsString($path, $display);
        self::assertThat($display, ConsoleDisplay::shows($path.'.'));
    }

    public function testAPathWithTwoSpacesThatAnErrorBlockWrapsAtIsFoundWhole(): void
    {
        $path = '/'.str_repeat('two  spaces/', 6).'broken.json';

        $display = $this->error('Cannot read a recorded head from '.$path.'.');

        self::assertMatchesRegularExpression('/two[ ]*\R[ ]*spaces/', $display);
        self::assertThat($display, ConsoleDisplay::shows($path.'.'));
    }

    public function testTwoSpacesInTheTextAreNotFoundWhereOneLineHasOne(): void
    {
        self::assertThat('/two spaces/broken.json', self::logicalNot(ConsoleDisplay::shows('/two  spaces/broken.json')));
    }

    public function testAPathAsLongAsLinuxAllowsIsFoundWhole(): void
    {
        $path = '/'.str_repeat('deep/', 817).'broke.json';

        $display = $this->error('Cannot read '.$path.'.');

        self::assertSame(4096, strlen($path));
        self::assertThat($display, ConsoleDisplay::shows($path.'.'));
    }

    public function testASpaceInTheTextIsNotFoundWhereTheDisplayHasNone(): void
    {
        self::assertThat('/ab/broken.json', self::logicalNot(ConsoleDisplay::shows('/a b/broken.json')));
    }

    public function testCharactersTheDisplaySeparatesOnOneLineAreNotFound(): void
    {
        self::assertThat('/a b/broken.json', self::logicalNot(ConsoleDisplay::shows('/ab/broken.json')));
    }

    public function testATextThatOnlyStartsInTheDisplayIsNotFound(): void
    {
        self::assertThat("read /ab/\n broken", self::logicalNot(ConsoleDisplay::shows('/ab/broken.json')));
    }

    public function testPatternCharactersInTheTextAreMatchedLiterally(): void
    {
        self::assertThat('read /shadow[1]*?.json', ConsoleDisplay::shows('/shadow[1]*?.json'));
        self::assertThat('read /shadow1.json', self::logicalNot(ConsoleDisplay::shows('/shadow[1]*?.json')));
    }

    public function testATextThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConsoleDisplay::shows("/x/\xFF.json");
    }

    public function testAFailureNamesTheTextLookedFor(): void
    {
        self::assertSame('shows \'/x/broken.json\', wrapped or not', ConsoleDisplay::shows('/x/broken.json')->toString());
    }

    private function error(string $message): string
    {
        $output = new BufferedOutput();
        new SymfonyStyle(new ArrayInput([]), $output)->error($message);

        return $output->fetch();
    }
}
