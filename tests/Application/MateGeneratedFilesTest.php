<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class MateGeneratedFilesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function generatedPaths(): iterable
    {
        yield 'canonical skill copies' => ['.agents/skills/mate-system-information/SKILL.md'];
        yield 'skill mirrors for Claude Code' => ['.claude/skills/mate-system-information'];
        yield 'root CLAUDE.md importing AGENTS.md' => ['CLAUDE.md'];
    }

    #[DataProvider('generatedPaths')]
    public function testGitIgnoresAFileThatMateDiscoverWrites(string $path): void
    {
        if (!file_exists(self::ROOT.'/.git')) {
            self::markTestSkipped('git check-ignore needs a git work tree.');
        }

        $check = new Process(['git', 'check-ignore', '--no-index', '--quiet', $path], self::ROOT);

        $check->run();

        self::assertSame(0, $check->getExitCode(), $path.' is not ignored');
    }
}
