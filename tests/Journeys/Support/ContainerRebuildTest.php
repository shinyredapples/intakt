<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

#[CoversClass(ContainerRebuild::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Kernel::class)]
final class ContainerRebuildTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const int CONSOLE_SECONDS = 120;

    private string $directory = '';

    public function testTheSeedSeesTheKeyAfterARebuildInTheSecondAKeylessContainerWasCompiled(): void
    {
        $this->console(['cache:warmup'], $this->withoutKeys());

        $this->rebuildStampingTheCacheWithEachConsolesStart()->rebuild($this->withKey(), self::CONSOLE_SECONDS);
        $output = $this->console(['intakt:sample:seed', $this->directory.'/sample', $this->configuration()], $this->withKey());

        self::assertStringContainsString('Wrote the sample project', $output);
    }

    public function testTheRunningWorkersServiceFilesStillLoadAfterARebuild(): void
    {
        $this->console(['cache:warmup'], $this->withoutKeys());
        $services = $this->serviceFiles();

        $this->rebuild()->rebuild($this->withKey(), self::CONSOLE_SECONDS);

        self::assertSame([], array_values(array_filter($services, static fn (string $file): bool => !is_file($file))));
    }

    public function testTheLoaderIsGoneWhenTheWarmupStarts(): void
    {
        $loader = $this->directory.'/cache/test/container.php';
        new Filesystem()->dumpFile($loader, '');
        $present = null;

        new ContainerRebuild($loader, static function () use ($loader, &$present): string {
            $present = is_file($loader);

            return '';
        })->rebuild($this->withKey(), self::CONSOLE_SECONDS);

        self::assertFalse($present);
    }

    public function testTheLoaderIsTheFileTheKernelCompilesItsContainerInto(): void
    {
        $this->console(['cache:warmup'], $this->withoutKeys());

        self::assertFileExists(ContainerRebuild::loaderOf(new Kernel('test', false), $this->cacheDirectory()));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-container-rebuild-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return list<string>
     */
    private function serviceFiles(): array
    {
        $files = [];

        foreach (new Finder()->files()->in($this->cacheDirectory())->path('/^Container[^\/]+\//') as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function rebuild(): ContainerRebuild
    {
        return new ContainerRebuild(ContainerRebuild::loaderOf(new Kernel('test', false), $this->cacheDirectory()), $this->console(...));
    }

    /**
     * @return array<string,string|false>
     */
    private function withoutKeys(): array
    {
        $variables = [SampleProject::HUGGING_FACE_TOKEN, ...array_merge(...array_map(static fn (Provider $provider): array => [...$provider->variables()], Provider::cases()))];

        return array_fill_keys($variables, false);
    }

    /**
     * @param list<string>               $arguments
     * @param array<string,string|false> $environment
     */
    private function console(array $arguments, array $environment, int $seconds = self::CONSOLE_SECONDS): string
    {
        return $this->outputOf([PHP_BINARY, ...$this->consoleCommand($arguments)], $environment, $seconds);
    }

    // Stamped inside each console process with its own start, because the framework's cache:clear takes a container compiled in the second it starts for one it built itself, as when the instance compiled one just before the rebuild.
    private function rebuildStampingTheCacheWithEachConsolesStart(): ContainerRebuild
    {
        $stamp = $this->directory.'/stamp.php';
        new Filesystem()->dumpFile($stamp, sprintf(
            '<?php foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(%s, FilesystemIterator::SKIP_DOTS)) as $file) { touch($file->getPathname(), $_SERVER["REQUEST_TIME"]); }',
            var_export($this->cacheDirectory(), true),
        ));

        return new ContainerRebuild(
            ContainerRebuild::loaderOf(new Kernel('test', false), $this->cacheDirectory()),
            fn (array $arguments, array $environment, int $seconds): string => $this->outputOf([PHP_BINARY, '-d', 'auto_prepend_file='.$stamp, ...$this->consoleCommand($arguments)], $environment, $seconds),
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function consoleCommand(array $arguments): array
    {
        return [self::ROOT.'/bin/console', '--no-interaction', ...$arguments];
    }

    /**
     * @param list<string>               $command
     * @param array<string,string|false> $environment
     */
    private function outputOf(array $command, array $environment, int $seconds): string
    {
        $process = new Process($command, self::ROOT, [...$this->instance(), ...$environment], null, $seconds);
        $process->run();

        self::assertTrue($process->isSuccessful(), implode(' ', $command).' failed: '.trim($process->getErrorOutput().$process->getOutput()));

        return $process->getOutput();
    }

    // Without the list of names this test's own Dotenv loaded, because a console handed it would load .env's empty key over the one set here.
    /**
     * @return array<string,string|false>
     */
    private function instance(): array
    {
        return [
            'SYMFONY_DOTENV_VARS' => false,
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'APP_CACHE_DIR' => $this->directory.'/cache',
            'APP_BUILD_DIR' => false,
            'APP_LOG_DIR' => $this->directory.'/log',
            'APP_SHARE_DIR' => $this->directory.'/share',
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/data.db',
            'INTAKT_CONFIG' => $this->configuration(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function withKey(): array
    {
        return ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY];
    }

    private function cacheDirectory(): string
    {
        return $this->directory.'/cache/test';
    }

    private function configuration(): string
    {
        return $this->directory.'/intakt.yaml';
    }
}
