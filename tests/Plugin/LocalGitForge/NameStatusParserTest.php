<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameStatusParser::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ForgeReadFailed::class)]
final class NameStatusParserTest extends TestCase
{
    public function testItTurnsNulSeparatedGitOutputIntoChangedFiles(): void
    {
        $files = new NameStatusParser()->parse("A\0src/a b.php\0M\0README.md\0D\0old\nname.php\0T\0link\0");

        self::assertEquals([
            new ChangedFile('src/a b.php', FileStatus::Added),
            new ChangedFile('README.md', FileStatus::Modified),
            new ChangedFile("old\nname.php", FileStatus::Deleted),
            new ChangedFile('link', FileStatus::Modified),
        ], $files);
    }

    public function testItReturnsNoFilesForNoOutput(): void
    {
        self::assertSame([], new NameStatusParser()->parse(''));
    }

    public function testItRefusesAStatusItDoesNotKnow(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new NameStatusParser()->parse("U\0conflict.php\0");
    }

    public function testItRefusesAStatusWithoutAPath(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new NameStatusParser()->parse("A\0");
    }
}
