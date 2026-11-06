<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use Closure;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsADeprecatedClassThroughAClosureLoaderFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedThroughAClosureLoaderDefinedOutsideTheSource(): void
    {
        // Because the static analysis of this repository reports a deprecated class named in code, and this fixture must load one.
        $name = implode('\\', ['Symfony', 'Component', 'HttpKernel', 'DependencyInjection', 'ServicesResetterInterface']);
        $composer = array_first(ClassLoader::getRegisteredLoaders());
        self::assertInstanceOf(ClassLoader::class, $composer);
        $file = $composer->findFile($name);
        self::assertIsString($file);

        // Because a closure loader whose file is outside Intakt's source must not become the caller.
        $directory = sys_get_temp_dir().'/intakt-closure-loader-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $loaderFile = $directory.'/loader.php';
        file_put_contents($loaderFile, '<?php return static function (string $class): void { if ('.var_export($name, true).' === $class) { require '.var_export($file, true).'; } };');

        try {
            $this->loadThrough($loaderFile, $name);
        } finally {
            unlink($loaderFile);
            rmdir($directory);
        }
    }

    private function loadThrough(string $loaderFile, string $name): void
    {
        $loader = require $loaderFile;
        self::assertInstanceOf(Closure::class, $loader);
        spl_autoload_register($loader, true, true);

        try {
            self::assertTrue(interface_exists($name));
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
