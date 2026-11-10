<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(Sandbox::class)]
final class SandboxTest extends TestCase
{
    private Sandbox $sandbox;

    private string $outside;

    protected function setUp(): void
    {
        $this->sandbox = Sandbox::ownedBy(self::class);
        $this->outside = sys_get_temp_dir().'/intakt-sandbox-target-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->outside.'/console', '#!/usr/bin/env php');
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
        new Filesystem()->remove($this->outside);
    }

    public function testTheSameOwnerGetsTheSameFolderInOneProcess(): void
    {
        self::assertSame($this->sandbox->root, Sandbox::ownedBy(self::class)->root);
    }

    public function testAnotherOwnerGetsAnotherFolder(): void
    {
        $other = Sandbox::ownedBy(TestCase::class);

        self::assertNotSame($this->sandbox->root, $other->root);

        $other->remove();
    }

    public function testReplacingTheFixtureRemovesTheFilesOfTheEarlierOne(): void
    {
        $this->sandbox->replaceFixtureWith('kernel-with-own-services');

        $this->sandbox->replaceFixtureWith('misspelled-service-id');

        self::assertFileDoesNotExist($this->sandbox->root.'/src/Kernel.php');
        self::assertFileDoesNotExist($this->sandbox->root.'/.gitignore');
        self::assertDirectoryDoesNotExist($this->sandbox->root.'/config');
        self::assertFileEquals(__DIR__.'/Fixtures/misspelled-service-id/src/Lookup.php.fixture', $this->sandbox->root.'/src/Lookup.php');
    }

    public function testReplacingTheFixtureKeepsTheCacheInVar(): void
    {
        $this->sandbox->replaceFixtureWith('kernel-with-own-services');
        $this->sandbox->write('var/cache/test/Intakt_KernelTestDebugContainer.xml', '<container/>');

        $this->sandbox->replaceFixtureWith('misspelled-service-id');

        self::assertStringEqualsFile($this->sandbox->root.'/var/cache/test/Intakt_KernelTestDebugContainer.xml', '<container/>');
    }

    public function testReplacingTheFixtureRemovesALinkedConsoleButNotItsTarget(): void
    {
        $this->sandbox->replaceFixtureWith('misspelled-service-id');
        $this->sandbox->write('bin/.keep', '');
        $this->sandbox->symlink('bin/console', $this->outside.'/console');

        $this->sandbox->replaceFixtureWith('misspelled-service-id');

        self::assertFalse(is_link($this->sandbox->root.'/bin/console'));
        self::assertStringEqualsFile($this->outside.'/console', '#!/usr/bin/env php');
    }

    public function testRemovingTheSandboxKeepsTheTargetOfALinkedConsole(): void
    {
        $this->sandbox->write('bin/.keep', '');
        $this->sandbox->symlink('bin/console', $this->outside.'/console');

        $this->sandbox->remove();

        self::assertDirectoryDoesNotExist($this->sandbox->root);
        self::assertStringEqualsFile($this->outside.'/console', '#!/usr/bin/env php');
    }

    public function testABorrowedKernelLinksThisRepositorysConsole(): void
    {
        $this->sandbox->borrowKernel();

        self::assertSame(dirname(__DIR__, 2).'/bin/console', readlink($this->sandbox->root.'/bin/console'));
    }
}
