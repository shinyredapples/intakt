<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Gate;

use Intakt\Plugin\Gate\Contract\DependencyInstall;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyInstall::class)]
final class DependencyInstallTest extends TestCase
{
    public function testPathsInsideTheProjectAreAccepted(): void
    {
        $install = new DependencyInstall(['composer.lock', 'tools/composer.lock'], 'composer install', 'vendor');

        self::assertSame(['composer.lock', 'tools/composer.lock'], $install->lockFiles);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideTheProject(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'the parent' => ['../composer.lock'];
        yield 'the parent through a folder' => ['tools/../../composer.lock'];
        yield 'not canonical' => ['./composer.lock'];
        yield 'the project itself' => ['.'];
    }

    #[DataProvider('pathsOutsideTheProject')]
    public function testALockFileOutsideTheProjectIsRefused(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DependencyInstall([$path], 'composer install', 'vendor');
    }

    #[DataProvider('pathsOutsideTheProject')]
    public function testAnInstalledFolderOutsideTheProjectIsRefused(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DependencyInstall(['composer.lock'], 'composer install', $path);
    }
}
