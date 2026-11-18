<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\GatesCache;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CodingStandardsGate::class)]
#[UsesClass(GatesCache::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
final class CodingStandardsGateCacheTest extends SandboxTestCase
{
    private const string HOME = __DIR__.'/../..';
    private const string FIXERS = self::HOME.'/config/gates/src/PhpCsFixer';
    private const string CHECKED = 'src/Core/Report/Domain/Report.php';

    public function testASecondRunReusesTheCacheTheFirstRunWroteUnderTheFixersHash(): void
    {
        $cache = $this->emptySandbox();
        $sandbox = $this->sandboxWith('clean');
        $file = new CodingStandardsGate()->cacheFile(self::FIXERS, $cache->root);
        self::assertIsString($file);

        $first = $sandbox->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=cs');

        self::assertTrue($first->passed('cs'), $first->output());
        self::assertFileExists($file);
        self::assertCount(1, $this->hashesIn($file));

        // The cache now claims the checked file already holds code that breaks the standards, so only a run that reads the cache passes it.
        $sandbox->write(self::CHECKED, (string) file_get_contents(__DIR__.'/Fixtures/fully-qualified-name-in-code/src/Report.php.fixture'));
        $this->recordAsChecked($sandbox->root.'/'.self::CHECKED, $file, $cache->root);

        $second = $sandbox->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=cs');

        self::assertTrue($second->passed('cs'), $second->output());
        $without = $sandbox->checkWith(['INTAKT_GATES_CACHE' => $this->emptySandbox()->root], '--only=cs');
        self::assertTrue($without->failed('cs'), $without->output());
    }

    public function testAChangedFixerSourcePicksADifferentCacheFile(): void
    {
        $fixers = $this->emptySandbox();

        $sources = glob(self::FIXERS.'/*.php');
        self::assertIsArray($sources);

        foreach ($sources as $source) {
            $fixers->write(basename($source), (string) file_get_contents($source));
        }

        $gate = new CodingStandardsGate();
        $before = $gate->cacheFile($fixers->root, '/cache');
        self::assertSame($gate->cacheFile(self::FIXERS, '/cache'), $before);

        $fixers->write('PlainFirstClassCallableFixer.php', file_get_contents(self::FIXERS.'/PlainFirstClassCallableFixer.php')."\n");

        $after = $gate->cacheFile($fixers->root, '/cache');
        self::assertIsString($before);
        self::assertIsString($after);
        self::assertStringStartsWith('/cache/php-cs-fixer-', $after);
        self::assertNotSame($before, $after);
    }

    public function testWithoutReadableFixersTheGateKeepsNoCache(): void
    {
        self::assertNull(new CodingStandardsGate()->cacheFile($this->emptySandbox()->root, '/cache'));
    }

    /**
     * @return array<string, string>
     */
    private function hashesIn(string $file): array
    {
        $cache = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cache);
        self::assertIsArray($cache['hashes'] ?? null);

        $hashes = [];

        foreach ($cache['hashes'] as $path => $hash) {
            self::assertIsString($hash);
            $hashes[(string) $path] = $hash;
        }

        return $hashes;
    }

    // Because the hash PHP CS Fixer keeps for a file is its own: a run with a single rule the file meets records it in a cache beside the gate's.
    private function recordAsChecked(string $checked, string $file, string $directory): void
    {
        $probe = $directory.'/probe.cache';
        $result = new ProcessRunner()->run(
            [self::HOME.'/vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--no-interaction', '--rules=encoding', '--using-cache=yes', '--cache-file='.$probe, $checked],
            $directory,
        );
        self::assertTrue($result->succeeded(), $result->output.$result->errors);

        $cache = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cache);
        $recorded = $this->hashesIn($probe);
        self::assertCount(1, $recorded);
        $cache['hashes'] = array_fill_keys(array_keys($this->hashesIn($file)), reset($recorded));
        file_put_contents($file, json_encode($cache, JSON_THROW_ON_ERROR));
    }
}
