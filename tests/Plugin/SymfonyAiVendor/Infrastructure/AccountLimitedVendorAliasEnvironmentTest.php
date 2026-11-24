<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Filesystem\Filesystem;

// Runs the alias tests the way PHPUnit does (setUp, the test, tearDown), because only the whole lifecycle shows what a later test in the same process inherits.
#[CoversNothing]
final class AccountLimitedVendorAliasEnvironmentTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $serverBefore = [];

    private string $root;

    #[DataProvider('aliasTests')]
    public function testAnAliasTestGivesTheCallerItsDirectoriesBack(string $aliasTest): void
    {
        $_SERVER['APP_CACHE_DIR'] = $this->root.'/cache';
        $_SERVER['APP_BUILD_DIR'] = $this->root.'/build';

        $this->runAsPhpUnitDoes($aliasTest);

        self::assertSame($this->root.'/cache', $_SERVER['APP_CACHE_DIR'] ?? null);
        self::assertSame($this->root.'/build', $_SERVER['APP_BUILD_DIR'] ?? null);
        self::assertDirectoryDoesNotExist($this->root);
    }

    #[DataProvider('aliasTests')]
    public function testAnAliasTestLeavesUnsetDirectoriesUnset(string $aliasTest): void
    {
        unset($_SERVER['APP_CACHE_DIR'], $_SERVER['APP_BUILD_DIR']);

        $this->runAsPhpUnitDoes($aliasTest);

        self::assertArrayNotHasKey('APP_CACHE_DIR', $_SERVER);
        self::assertArrayNotHasKey('APP_BUILD_DIR', $_SERVER);
    }

    /** @return iterable<string, array{string}> */
    public static function aliasTests(): iterable
    {
        yield 'the alias' => ['testOutsideTheTestEnvironmentTheVendorContractIsTheAccountLimitedVendor'];
        yield 'the build directory' => ['testTheCallersBuildDirectoryStaysUntouched'];
    }

    protected function setUp(): void
    {
        $this->serverBefore = $_SERVER;
        $this->root = sys_get_temp_dir().'/intakt-vendor-alias-caller-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBefore;
        new Filesystem()->remove($this->root);
    }

    private function runAsPhpUnitDoes(string $aliasTest): void
    {
        $test = new AccountLimitedVendorAliasTest($aliasTest);
        new ReflectionMethod($test, 'setUp')->invoke($test);

        try {
            new ReflectionMethod($test, $aliasTest)->invoke($test);
        } finally {
            new ReflectionMethod($test, 'tearDown')->invoke($test);
        }
    }
}
