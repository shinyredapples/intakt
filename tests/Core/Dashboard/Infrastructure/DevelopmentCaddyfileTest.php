<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

// Covers nothing because the development Caddyfile and compose override are files FrankenPHP and Docker Compose read, not classes.
#[Group('hub')]
#[CoversNothing]
final class DevelopmentCaddyfileTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    public function testTheDevelopmentCaddyfileWatchesPhpTwigAndConfigFilesInBothWorkers(): void
    {
        $frankenphp = $this->frankenphp();

        foreach (['app', 'mcp'] as $name) {
            $watch = (array) ($this->workerIn($this->adapted($frankenphp), $name)['watch'] ?? []);

            self::assertContains('src/**/*.php', $watch, $name);
            self::assertContains('templates/**/*.twig', $watch, $name);
            self::assertContains('config/**/*.{php,yaml}', $watch, $name);
        }
    }

    public function testTheDevelopmentCaddyfileReloadsTheBrowserAndWatchesNoCacheFolder(): void
    {
        $encoded = json_encode($this->adapted($this->frankenphp()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        preg_match_all('/"watch":\[([^\]]*)\]/', $encoded, $matches);

        self::assertStringContainsString('"hot_reload":{"topic":"","watch":["src/**/*.php"', $encoded);
        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $patterns) {
            self::assertStringNotContainsString('var/', $patterns);
            self::assertStringNotContainsString('"./**', $patterns);
        }
    }

    public function testTheDevelopmentCaddyfileDiffersFromTheShippedOneOnlyByItsWatchHotReloadAndAnonymousLines(): void
    {
        $shipped = $this->lines(self::ROOT.'/Caddyfile');
        $development = $this->lines(self::ROOT.'/docker/dev/Caddyfile');

        $kept = [];
        $inHotReload = false;

        foreach ($development as $line) {
            $inHotReload = $inHotReload || 'hot_reload {' === $line;

            if (!$inHotReload && !str_starts_with($line, 'watch ') && !str_starts_with($line, 'hot_reload') && 'anonymous' !== $line) {
                $kept[] = $line;
            }

            $inHotReload = $inHotReload && '}' !== $line;
        }

        // Braces are balanced by the same change, so the lines that open and close the route block are left out of both sides.
        self::assertSame($this->withoutBlocks($shipped), $this->withoutBlocks($kept));
    }

    public function testTheShippedCaddyfileDeclaresNoWatchSoTheImageRunsAsItIsBuilt(): void
    {
        $shipped = (string) file_get_contents(self::ROOT.'/Caddyfile');

        self::assertSame(0, preg_match('/^\s*(watch|hot_reload)\b/m', $shipped));
    }

    public function testTheDefaultComposeFileMountsNoSourceAndSetsNoDevelopmentEnvironment(): void
    {
        /** @var array{services: array{intakt: array{volumes: list<string>, environment: array<string, string>}}} $compose */
        $compose = Yaml::parse((string) file_get_contents(self::ROOT.'/compose.yaml'));

        self::assertSame(['intakt-data:/app/var/data'], $compose['services']['intakt']['volumes']);
        self::assertArrayNotHasKey('APP_ENV', $compose['services']['intakt']['environment']);
    }

    public function testTheDevelopmentOverrideMountsTheCheckoutAndTheDevelopmentCaddyfile(): void
    {
        /** @var array{services: array{intakt: array{volumes: list<string>, environment: array<string, string>}}} $override */
        $override = Yaml::parse((string) file_get_contents(self::ROOT.'/compose.dev.yaml'));

        self::assertContains('.:/app', $override['services']['intakt']['volumes']);
        self::assertContains('./docker/dev/Caddyfile:/app/Caddyfile:ro', $override['services']['intakt']['volumes']);
        self::assertSame('dev', $override['services']['intakt']['environment']['APP_ENV']);
    }

    // The checkout under /app may belong to another user id than the image's, so every path the entrypoint writes under var/ must sit on a named volume, which Docker fills from the image's own var/ and its owner.
    public function testEveryPathTheEntrypointWritesUnderVarLiesOnANamedVolumeOfTheOverride(): void
    {
        /** @var array{services: array{intakt: array{volumes: list<string>}}} $override */
        $override = Yaml::parse((string) file_get_contents(self::ROOT.'/compose.dev.yaml'));
        $named = [];

        foreach ($override['services']['intakt']['volumes'] as $volume) {
            [$source, $target] = explode(':', $volume);

            if (1 === preg_match('/^\w[\w-]*$/', $source)) {
                $named[] = rtrim($target, '/').'/';
            }
        }

        preg_match_all('#\bvar/[\w./-]*#', (string) file_get_contents(self::ROOT.'/docker/entrypoint.sh'), $written);
        self::assertContains('var/sample-ready', $written[0]);

        foreach (array_unique($written[0]) as $path) {
            $onVolume = array_filter($named, static fn (string $mount): bool => str_starts_with('/app/'.$path, $mount));
            self::assertNotEmpty($onVolume, $path.' is not on a named volume');
        }
    }

    public function testTheBuildContextLeavesOutTheDevelopmentFiles(): void
    {
        $excluded = file(self::ROOT.'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($excluded);

        self::assertContains('compose.dev.yaml', $excluded);
        self::assertContains('docker/dev', $excluded);
        self::assertNotContains('docker', $excluded);
    }

    public function testNoTemplateTheImageCopiesCarriesTheBrowserReloadClient(): void
    {
        $excluded = file(self::ROOT.'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($excluded);
        $templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/templates', FilesystemIterator::SKIP_DOTS));
        $carriers = 0;

        foreach ($templates as $template) {
            $path = substr((string) $template, \strlen(self::ROOT) + 1);
            if (!str_contains((string) file_get_contents((string) $template), 'frankenphp-hot-reload:url')) {
                continue;
            }
            ++$carriers;
            $ignored = array_filter($excluded, static fn (string $rule): bool => str_starts_with($path, rtrim($rule, '/').'/'));
            self::assertNotEmpty($ignored, $path.' carries the reload client into the image');
        }

        self::assertGreaterThan(0, $carriers);
    }

    private function frankenphp(): string
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        return $frankenphp;
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        $lines = array_map(trim(...), explode("\n", (string) file_get_contents($path)));

        return array_values(array_filter($lines, static fn (string $line): bool => !str_starts_with($line, '#')));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function withoutBlocks(array $lines): array
    {
        return array_values(array_diff($lines, ['route {', '}']));
    }

    /**
     * @return array<mixed>
     */
    private function adapted(string $frankenphp): array
    {
        $directory = sys_get_temp_dir().'/intakt-devcaddyfile-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        try {
            $filesystem->copy(self::ROOT.'/docker/dev/Caddyfile', $directory.'/Caddyfile');
            $filesystem->copy(self::ROOT.'/.env', $directory.'/.env');
            $process = new Process([$frankenphp, 'adapt', '--config', 'Caddyfile', '--adapter', 'caddyfile', '--envfile', '.env'], $directory, ['MERCURE_JWT_SECRET' => 'a-test-key-that-is-at-least-thirty-two-bytes-long', 'MERCURE_JWT_ISSUER' => 'http://localhost:7790', 'CADDY_ADMIN' => false]);
            $process->mustRun();
        } finally {
            $filesystem->remove($directory);
        }

        $config = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private function workerIn(array $config, string $name): array
    {
        foreach (array_filter((array) ($config['workers'] ?? []), is_array(...)) as $worker) {
            if (($worker['name'] ?? null) === $name) {
                return $worker;
            }
        }

        foreach (array_filter($config, is_array(...)) as $nested) {
            $worker = $this->workerIn($nested, $name);

            if ([] !== $worker) {
                return $worker;
            }
        }

        return [];
    }
}
