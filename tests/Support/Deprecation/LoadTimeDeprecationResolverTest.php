<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(LoadTimeDeprecationResolver::class)]
// Because the PHPUnit runs it starts keep their result cache in var/cache/ of the checkout, which every test worker shares.
#[Group('serial')]
final class LoadTimeDeprecationResolverTest extends TestCase
{
    public function testTheCodeThatMadeTheAutoloaderLoadTheClassIsTheCaller(): void
    {
        $autoloader = new \ReflectionClass(ClassLoader::class)->getFileName();
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'Composer\Autoload\{closure}', 'file' => $autoloader],
            ['function' => 'loadClass', 'file' => $autoloader],
            ['function' => 'testIt', 'file' => '/app/tests/SomeTest.php'],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertSame('/vendor/a/Deprecated.php', $resolution->callee());
        self::assertSame('/app/tests/SomeTest.php', $resolution->caller());
    }

    public function testACallerOutsideTheAutoloaderStaysTheCaller(): void
    {
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'oldMethod', 'file' => '/app/src/Caller.php'],
            ['function' => 'run', 'file' => '/app/tests/SomeTest.php'],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertSame('/app/src/Caller.php', $resolution->caller());
    }

    public function testATraceOfTheAutoloaderAloneHasNoCaller(): void
    {
        $autoloader = new \ReflectionClass(ClassLoader::class)->getFileName();
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'loadClass', 'file' => $autoloader],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertFalse($resolution->hasCaller());
    }

    public function testATestThatLoadsADeprecatedVendorClassFailsTheRun(): void
    {
        $run = $this->runFixture('LoadsADeprecatedClassFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassFixture::testItLoadsAClassThatIsDeprecatedInTheVendorPackage', $run->getOutput());
    }

    private function runFixture(string $file): Process
    {
        $root = dirname(__DIR__, 3);
        $run = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', '--configuration', $root.'/phpunit.dist.xml', __DIR__.'/Fixtures/'.$file], $root);
        $run->run();

        return $run;
    }
}
