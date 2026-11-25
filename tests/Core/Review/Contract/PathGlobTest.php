<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathGlob::class)]
#[CoversClass(GlobCannotBeEvaluated::class)]
final class PathGlobTest extends TestCase
{
    private string|false $backtrackLimit;

    private string|false $jit;

    public function testAGlobThatMatchesReportsAMatch(): void
    {
        self::assertTrue(new PathGlob('src/**/*.php')->matches('src/a/b.php'));
    }

    public function testAGlobThatDoesNotMatchReportsNoMatch(): void
    {
        self::assertFalse(new PathGlob('src/**/*.php')->matches('docs/b.md'));
    }

    #[DataProvider('globSemantics')]
    public function testAGlobMatchesAPathOnlyWhereItsSemanticsSay(string $glob, string $path, bool $matches): void
    {
        self::assertSame($matches, new PathGlob($glob)->matches($path));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function globSemantics(): iterable
    {
        yield 'one star does not reach into subfolders' => ['docs/*.md', 'docs/a/b.md', false];
        yield 'a path that is not valid UTF-8 still matches' => ['config/security/**', "config/security/\xFF.php", true];
        yield 'a path with a newline still matches a double star' => ['config/security/**', "config/security/a\nb.php", true];
        yield 'a double star between slashes matches no folder at all' => ['src/**/Security/**', 'src/Security/Door.php', true];
        yield 'a double star between slashes matches nested folders with a newline' => ['src/**/Security/**', "src/x\ny/Security/a.php", true];
        yield 'a double star between slashes does not match a folder that only ends in the name' => ['src/**/Security/**', 'src/NoSecurity/Door.php', false];
        yield 'a leading double star matches a folder at the root' => ['**/Security/**', 'Security/Door.php', true];
        yield 'a leading double star matches a nested folder' => ['**/Security/**', 'a/Security/Door.php', true];
        yield 'a leading double star does not match a folder that only ends in the name' => ['**/Security/**', 'NoSecurity/Door.php', false];
        yield 'consecutive double stars between slashes match no folder at all' => ['src/**/**/Security/**', 'src/Security/Door.php', true];
        yield 'consecutive double stars between slashes match a nested folder' => ['src/**/**/Security/**', 'src/a/Security/Door.php', true];
        yield 'consecutive leading double stars match a folder at the root' => ['**/**/Security/**', 'Security/Door.php', true];
        yield 'consecutive leading double stars do not match a folder that only ends in the name' => ['**/**/Security/**', 'NoSecurity/Door.php', false];
        yield 'a one-character wildcard matches one character of a UTF-8 path' => ['src/?.php', 'src/é.php', true];
    }

    public function testAGlobThatHitsAPcreLimitFailsClosedNamingTheGlob(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');
        $glob = '**/a/**/a/**/c.php';

        try {
            new PathGlob($glob)->matches(str_repeat('a/', 40).'d.php');
            self::fail('The glob should not have been evaluated.');
        } catch (GlobCannotBeEvaluated $failure) {
            self::assertStringContainsString($glob, $failure->getMessage());
            self::assertStringContainsString('Backtrack limit exhausted', $failure->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', (string) $this->backtrackLimit);
        ini_set('pcre.jit', (string) $this->jit);
    }
}
