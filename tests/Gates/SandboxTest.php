<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\LongDashesGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(Sandbox::class)]
#[UsesClass(LongDashesGate::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(Workspace::class)]
final class SandboxTest extends TestCase
{
    private Sandbox $sandbox;

    private string $outside;

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

    public function testBootingTheFixtureKernelKeepsTheCallerOfTheRuleFixture(): void
    {
        $this->sandbox->replaceFixtureWith('private-get-service-id-in-union');

        $this->sandbox->bootFixtureKernel();

        self::assertFileEquals(__DIR__.'/Fixtures/private-get-service-id-in-union/src/Lookup.php.fixture', $this->sandbox->root.'/src/Lookup.php');
        self::assertFileExists($this->sandbox->root.'/src/Kernel.php');
    }

    public function testBootingTheFixtureKernelLeavesItsOwnCallerOut(): void
    {
        $this->sandbox->bootFixtureKernel();

        self::assertFileDoesNotExist($this->sandbox->root.'/src/Lookup.php');
        self::assertFileExists($this->sandbox->root.'/config/services.yaml');
    }

    public function testAGateRunInTheTestProcessReadsAsItsCheckLine(): void
    {
        $this->sandbox->write('README.md', "Plain \u{2014} text\n");

        $run = $this->sandbox->runGate(new LongDashesGate());

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('README.md:1: U+2014', $run->findingsOf('dashes'));
    }

    public function testAPassingGateRunInTheTestProcessExitsWithZero(): void
    {
        $this->sandbox->write('README.md', "Plain text\n");

        $run = $this->sandbox->runGate(new LongDashesGate());

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('dashes'), $run->output());
        self::assertStringContainsString('commits skipped, no git history', $run->noteOf('dashes'));
    }

    public function testAGateResultInTheTestProcessKeepsTheFindingsPastTheTenTheCheckLineShows(): void
    {
        foreach (range(1, 12) as $number) {
            $this->sandbox->write('notes-'.$number.'.md', "Plain \u{2014} text\n");
        }

        $result = $this->sandbox->gateResult(new LongDashesGate());

        self::assertCount(12, $result->findings);
        self::assertSame('2', $this->sandbox->runGate(new LongDashesGate())->omittedOf('dashes'));
    }

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
}
