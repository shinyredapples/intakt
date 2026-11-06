<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\Process\Process;

#[CoversClass(LoadTimeDeprecationResolver::class)]
// Because the PHPUnit runs it starts keep their result cache in var/cache/ of the checkout, which every test worker shares.
#[Group('serial')]
final class LoadTimeDeprecationResolverTest extends TestCase
{
    private const string APPLICATION_CONFIGURATION = 'phpunit.dist.xml';

    private const string GATES_CONFIGURATION = 'phpunit.gates.xml';

    public function testTheCodeThatMadeTheAutoloaderLoadTheClassIsTheCaller(): void
    {
        $autoloader = $this->autoloader();
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'include', 'file' => $autoloader],
            ['function' => '{closure:Composer\Autoload\ClassLoader::initializeIncludeClosure():575}', 'class' => ClassLoader::class, 'file' => $autoloader],
            ['function' => 'loadClass', 'class' => ClassLoader::class],
            ['function' => 'interface_exists', 'file' => '/app/tests/SomeTest.php'],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertSame('/vendor/a/Deprecated.php', $resolution->callee());
        self::assertSame('/app/tests/SomeTest.php', $resolution->caller());
    }

    public function testTheCodeThatMadeARegisteredClosureLoaderLoadTheClassIsTheCaller(): void
    {
        $loader = static function (string $class): void {};
        $closure = new ReflectionFunction($loader);
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'require', 'file' => $closure->getFileName()],
            ['function' => $closure->getName()],
            ['function' => 'interface_exists', 'file' => '/app/tests/SomeTest.php'],
        ];

        spl_autoload_register($loader);

        try {
            $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertSame('/app/tests/SomeTest.php', $resolution->caller());
    }

    public function testAClassThatExtendsTheDeprecatedClassIsTheCaller(): void
    {
        $autoloader = $this->autoloader();
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'include', 'file' => $autoloader],
            ['function' => 'loadClass', 'class' => ClassLoader::class, 'file' => '/vendor/b/Child.php'],
            ['function' => 'include', 'file' => $autoloader],
            ['function' => 'loadClass', 'class' => ClassLoader::class, 'file' => '/app/src/Caller.php'],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertSame('/vendor/b/Child.php', $resolution->caller());
    }

    public function testADeprecatedCallMadeWhileAnIntaktFileLoadsAtAVendorsRequestKeepsThatFileAsTheCaller(): void
    {
        $resolution = new LoadTimeDeprecationResolver()->resolve($this->loadTimeCallTrace('/app/tests/Source.php', '/vendor/Dispatcher.php'), 'deprecated');

        self::assertSame('/app/tests/Source.php', $resolution->caller());
    }

    public function testADeprecatedCallMadeWhileAVendorFileLoadsAtTheTestsRequestKeepsTheVendorFileAsTheCaller(): void
    {
        $resolution = new LoadTimeDeprecationResolver()->resolve($this->loadTimeCallTrace('/vendor/Consumer.php', '/app/tests/Caller.php'), 'deprecated');

        self::assertSame('/vendor/Consumer.php', $resolution->caller());
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
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'loadClass', 'class' => ClassLoader::class],
        ];

        $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');

        self::assertFalse($resolution->hasCaller());
    }

    public function testATestThatLoadsADeprecatedVendorClassFailsTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/LoadsADeprecatedClassFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassFixture::testItLoadsAClassThatIsDeprecatedInTheVendorPackage', $run->getOutput());
    }

    public function testATestHelperThatLoadsADeprecatedVendorClassFailsTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/LoadsADeprecatedClassThroughAHelperFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassThroughAHelperFixture::testItLoadsAClassThatIsDeprecatedThroughATestHelper', $run->getOutput());
    }

    public function testATestThatLoadsADeprecatedVendorClassThroughTheDebugClassLoaderFailsTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/LoadsADeprecatedClassThroughTheDebugClassLoaderFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassThroughTheDebugClassLoaderFixture::testItLoadsAClassThatIsDeprecatedWhileTheDebugClassLoaderIsEnabled', $run->getOutput());
    }

    public function testATestThatLoadsADeprecatedVendorClassThroughAClosureLoaderFailsTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/LoadsADeprecatedClassThroughAClosureLoaderFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassThroughAClosureLoaderFixture::testItLoadsAClassThatIsDeprecatedThroughAClosureLoaderDefinedOutsideTheSource', $run->getOutput());
    }

    public function testAnIntaktFileWhoseLoadTimeCodeCallsADeprecatedVendorFunctionAtAVendorsRequestFailsTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/VendorCodeLoadsAFileThatCallsADeprecatedFunctionFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('intakt_deprecated_vendor_function() is deprecated', $run->getOutput());
        self::assertStringContainsString('VendorCodeLoadsAFileThatCallsADeprecatedFunctionFixture::testVendorCodeAsksForAnIntaktFileWhoseLoadTimeCodeCallsADeprecatedVendorFunction', $run->getOutput());
    }

    public function testAVendorFileWhoseLoadTimeCodeCallsAnotherVendorsDeprecatedFunctionAtTheTestsRequestPassesTheRun(): void
    {
        $run = $this->runFixture(self::APPLICATION_CONFIGURATION, __DIR__.'/Fixtures/LoadsAVendorFileThatCallsADeprecatedFunctionFixture.php');

        self::assertSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('OK (1 test, 2 assertions)', $run->getOutput());
    }

    public function testTheCodeThatMadeARegisteredAutoloaderLoadTheClassIsTheCaller(): void
    {
        $debugClassLoader = new ReflectionClass(DebugClassLoader::class)->getFileName();
        self::assertIsString($debugClassLoader);
        $trace = [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/a/Deprecated.php'],
            ['function' => 'include', 'file' => $debugClassLoader],
            ['function' => 'loadClass', 'class' => DebugClassLoader::class],
            ['function' => 'interface_exists', 'file' => '/app/tests/SomeTest.php'],
        ];

        DebugClassLoader::enable();

        try {
            $resolution = new LoadTimeDeprecationResolver()->resolve($trace, 'deprecated');
        } finally {
            DebugClassLoader::disable();
        }

        self::assertSame('/app/tests/SomeTest.php', $resolution->caller());
    }

    public function testAGateTestThatLoadsADeprecatedVendorClassFailsTheGatesRun(): void
    {
        $run = $this->runFixture(self::GATES_CONFIGURATION, $this->gateFixture('LoadsADeprecatedClassGateFixture.php'));

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassGateFixture::testItLoadsAClassThatIsDeprecatedInTheVendorPackage', $run->getOutput());
    }

    public function testAGateTestHelperThatLoadsADeprecatedVendorClassFailsTheGatesRun(): void
    {
        $run = $this->runFixture(self::GATES_CONFIGURATION, $this->gateFixture('LoadsADeprecatedClassThroughAHelperGateFixture.php'));

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('ServicesResetterInterface" interface is deprecated', $run->getOutput());
        self::assertStringContainsString('LoadsADeprecatedClassThroughAHelperGateFixture::testItLoadsAClassThatIsDeprecatedThroughAGateTestHelper', $run->getOutput());
    }

    private function autoloader(): string
    {
        $file = new ReflectionClass(ClassLoader::class)->getFileName();
        self::assertIsString($file);

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTimeCallTrace(string $loadingFile, string $askingFile): array
    {
        return [
            ['function' => 'trigger_deprecation', 'file' => '/vendor/Api.php'],
            ['function' => 'oldMethod', 'file' => $loadingFile],
            ['function' => 'include', 'file' => $this->autoloader()],
            ['function' => 'loadClass', 'class' => ClassLoader::class, 'file' => $askingFile],
        ];
    }

    private function gateFixture(string $file): string
    {
        return dirname(__DIR__, 2).'/Gates/Deprecation/'.$file;
    }

    private function runFixture(string $configuration, string $file): Process
    {
        $root = dirname(__DIR__, 3);
        $run = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', '--configuration', $root.'/'.$configuration, $file], $root);
        $run->run();

        return $run;
    }
}
