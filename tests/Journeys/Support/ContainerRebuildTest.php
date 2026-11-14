<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

#[CoversClass(ContainerRebuild::class)]
final class ContainerRebuildTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const int CONSOLE_SECONDS = 120;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-container-rebuild-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheSeedSeesTheKeyAfterARebuildInTheSecondAKeylessContainerWasCompiled(): void
    {
        $this->console(['cache:warmup'], $this->withoutKeys());
        $this->stampTheContainerWithTheNextSecond();

        $this->rebuild()->rebuild($this->withKey(), self::CONSOLE_SECONDS);
        $output = $this->console(['intakt:sample:seed', $this->directory.'/sample', $this->configuration()], $this->withKey());

        self::assertStringContainsString('Wrote the sample project', $output);
    }

    public function testEveryDirectoryIsGoneWhenTheWarmupStarts(): void
    {
        $directories = [$this->directory.'/cache/test', $this->directory.'/build/test'];
        new Filesystem()->dumpFile($directories[0].'/container.php', '');
        new Filesystem()->dumpFile($directories[1].'/container.php', '');
        $left = null;

        new ContainerRebuild($directories, static function () use ($directories, &$left): string {
            $left = array_values(array_filter($directories, is_dir(...)));

            return '';
        })->rebuild($this->withKey(), self::CONSOLE_SECONDS);

        self::assertSame([], $left);
    }

    // Because the framework's cache:clear takes a container compiled in the second it starts for one it built itself, as when the instance or a command before the rebuild compiled one.
    private function stampTheContainerWithTheNextSecond(): void
    {
        $clock = new NativeClock();
        $now = $clock->now();
        $clock->sleep(1 - (float) $now->format('0.u'));
        $second = $now->getTimestamp() + 1;

        foreach (new Finder()->files()->in($this->cacheDirectory()) as $file) {
            touch($file->getPathname(), $second);
        }
    }

    private function rebuild(): ContainerRebuild
    {
        return new ContainerRebuild([$this->cacheDirectory()], $this->console(...));
    }

    /**
     * @param list<string>                $arguments
     * @param array<string,string|false> $environment
     */
    private function console(array $arguments, array $environment, int $seconds = self::CONSOLE_SECONDS): string
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/console', '--no-interaction', ...$arguments], self::ROOT, [...$this->instance(), ...$environment], null, $seconds);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'bin/console '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput().$process->getOutput()));

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
     * @return array<string,string|false>
     */
    private function withoutKeys(): array
    {
        $variables = [SampleProject::HUGGING_FACE_TOKEN, ...array_merge(...array_map(static fn (Provider $provider): array => $provider->variables(), Provider::cases()))];

        return array_fill_keys($variables, false);
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
