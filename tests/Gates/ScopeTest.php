<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scope::class)]
final class ScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function analyzedExecutables(): iterable
    {
        yield 'bin/check' => ['bin/check'];
        yield 'bin/instance' => ['bin/instance'];
        yield 'bin/probe' => ['bin/probe'];
    }

    #[DataProvider('analyzedExecutables')]
    public function testExtensionlessPhpExecutablesAreInScope(string $path): void
    {
        self::assertTrue($this->scope()->includes($path));
    }

    public function testOtherExtensionlessFilesAreOutOfScope(): void
    {
        self::assertFalse($this->scope()->includes('bin/other'));
    }

    public function testTheRepositoryExecutablesAreAmongAllPhpFiles(): void
    {
        $files = new Scope(dirname(__DIR__, 2), new ProcessRunner())->allPhpFiles();

        self::assertContains('bin/probe', $files);
        self::assertContains('bin/instance', $files);
    }

    private function scope(): Scope
    {
        return new Scope(sys_get_temp_dir(), new ProcessRunner());
    }
}
