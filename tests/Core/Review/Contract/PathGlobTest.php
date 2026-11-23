<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
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
