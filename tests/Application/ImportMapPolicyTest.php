<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use DateInterval;
use DateTimeImmutable;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Command\ImportMapUpdateCommand;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\AssetMapper\ImportMap\ImportMapManager;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\ImportMapUpdateChecker;
use Symfony\Component\AssetMapper\ImportMap\ImportMapVersionChecker;
use Symfony\Component\AssetMapper\ImportMap\PackageRequireOptions;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageDownloader;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\AssetMapper\ImportMap\Resolver\PackageResolverInterface;
use Symfony\Component\AssetMapper\ImportMap\Resolver\ResolvedImportMapPackage;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Yaml\Yaml;

// The two import map settings of config/packages/asset_mapper.yaml applied by AssetMapper's own classes; npm and jsDelivr are replaced, so the run stays offline.
#[CoversNothing]
final class ImportMapPolicyTest extends KernelTestCase
{
    private const string NOW = '2026-11-19T12:00:00+00:00';

    private const string PINNED = '8.0.23';

    private const string NEWER = '8.0.24';

    private const string MODULE = 'export const answer = 42;';

    private string $directory;

    private string $served = self::MODULE;

    private ?ImportMapGenerator $wiredGenerator = null;

    public function testTheRepositoryAsksForSha384IntegrityInTheImportMap(): void
    {
        self::assertSame(['sha384'], $this->repositoryAssetMapperSetting('importmap_integrity_algorithms'));
    }

    public function testTheMinimumReleaseAgeIsTheComposerCooldownPeriod(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['config'] ?? null);
        self::assertIsArray($composer['config']['policy'] ?? null);
        self::assertIsArray($composer['config']['policy']['cooldown'] ?? null);
        $period = $composer['config']['policy']['cooldown']['period'] ?? null;
        self::assertIsString($period);
        $epoch = new DateTimeImmutable('@0');

        self::assertSame($epoch->modify('+'.$period)->getTimestamp(), $this->wiredMinimumReleaseAge());
    }

    public function testTheContainerRendersTheImportMapWithTheSha384OfEachModule(): void
    {
        $this->served = self::MODULE;

        $importMap = $this->renderedImportMap();

        self::assertSame(['/assets/app-1.js' => $this->sha384Of(self::MODULE)], $importMap['integrity'] ?? null);
    }

    public function testAModuleChangedAfterTheImportMapWasCompiledNoLongerMatchesItsIntegrityHash(): void
    {
        $this->compileTheImportMapOf(self::MODULE);
        $this->served = 'export const answer = 43;';

        $importMap = $this->renderedImportMap();

        self::assertIsArray($importMap['integrity'] ?? null);
        self::assertSame($this->sha384Of(self::MODULE), $importMap['integrity']['/assets/app-1.js'] ?? null);
        self::assertNotSame($this->sha384Of('export const answer = 43;'), $importMap['integrity']['/assets/app-1.js']);
    }

    public function testAMapRenderedWithoutTheCompiledImportMapCarriesTheHashOfTheChangedModule(): void
    {
        $this->compileTheImportMapOf(self::MODULE);
        $pinned = $this->sha384Of(self::MODULE);
        new Filesystem()->remove($this->directory.'/public/assets/importmap.json');
        $this->served = 'export const answer = 43;';

        $importMap = $this->renderedImportMap();

        self::assertIsArray($importMap['integrity'] ?? null);
        self::assertSame($this->sha384Of('export const answer = 43;'), $importMap['integrity']['/assets/app-1.js'] ?? null);
        self::assertNotSame($pinned, $importMap['integrity']['/assets/app-1.js']);
    }

    public function testTheUpdateCommandKeepsThePinnedVersionWhenTheNewerOneIsYoungerThanTheMinimumReleaseAge(): void
    {
        $this->importMapPinning(self::PINNED);

        $this->runTheUpdateCommandWithTheNewerVersionPublishedSecondsAgo($this->wiredMinimumReleaseAge() - 1);

        self::assertSame(self::PINNED, $this->pinnedVersion());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function agesAtOrPastTheMinimum(): iterable
    {
        yield 'exactly the minimum' => [0];
        yield 'a day past the minimum' => [86400];
    }

    #[DataProvider('agesAtOrPastTheMinimum')]
    public function testTheUpdateCommandTakesTheNewerVersionOnceItReachesTheMinimumReleaseAge(int $pastTheMinimum): void
    {
        $this->importMapPinning(self::PINNED);

        $this->runTheUpdateCommandWithTheNewerVersionPublishedSecondsAgo($this->wiredMinimumReleaseAge() + $pastTheMinimum);

        self::assertSame(self::NEWER, $this->pinnedVersion());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-importmap-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    private function repositoryAssetMapperSetting(string $key): mixed
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/asset_mapper.yaml');
        self::assertIsArray($config);
        self::assertIsArray($config['framework'] ?? null);
        self::assertIsArray($config['framework']['asset_mapper'] ?? null);

        return $config['framework']['asset_mapper'][$key] ?? self::fail('asset_mapper.yaml sets no '.$key);
    }

    private function wiredMinimumReleaseAge(): int
    {
        $checker = self::getContainer()->get('asset_mapper.importmap.update_checker');
        self::assertInstanceOf(ImportMapUpdateChecker::class, $checker);

        return $checker->getMinimumReleaseAge();
    }

    // Because asset-map:compile stores this raw data, a deployed install renders the hash of the bytes it compiled.
    private function compileTheImportMapOf(string $content): void
    {
        $this->served = $content;
        $data = $this->wiredGenerator()->getRawImportMapData();
        new CompiledAssetMapperConfigReader($this->directory.'/public/assets')->saveConfig('importmap.json', $data);
        new CompiledAssetMapperConfigReader($this->directory.'/public/assets')->saveConfig('entrypoint.app.json', []);
    }

    // The container's own generator, so the integrity algorithms are the wired ones; only its file sources point at this test's directory.
    private function wiredGenerator(): ImportMapGenerator
    {
        return $this->wiredGenerator ??= $this->wireTheGeneratorToThisDirectory();
    }

    private function wireTheGeneratorToThisDirectory(): ImportMapGenerator
    {
        new Filesystem()->dumpFile($this->directory.'/importmap.php', '<?php return '.var_export(['app' => ['path' => './assets/app.js', 'entrypoint' => true]], true).';');
        $container = self::getContainer();
        $container->set('asset_mapper', $this->assetMapperServingTheCurrentModule());
        $container->set('asset_mapper.compiled_asset_mapper_config_reader', new CompiledAssetMapperConfigReader($this->directory.'/public/assets'));
        $container->set('asset_mapper.importmap.config_reader', $this->configReader());
        $generator = $container->get('asset_mapper.importmap.generator');
        self::assertInstanceOf(ImportMapGenerator::class, $generator);

        return $generator;
    }

    private function assetMapperServingTheCurrentModule(): AssetMapperInterface
    {
        $assetMapper = self::createStub(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(fn (): MappedAsset => new MappedAsset(
            'app.js',
            $this->directory.'/assets/app.js',
            '/assets/app.js',
            '/assets/app-1.js',
            $this->served,
            '1',
            false,
        ));

        return $assetMapper;
    }

    /**
     * @return array<mixed>
     */
    private function renderedImportMap(): array
    {
        $html = new ImportMapRenderer($this->wiredGenerator())->render('app');
        self::assertSame(1, preg_match('~<script type="importmap">(.*?)</script>~s', $html, $match));
        $importMap = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($importMap);

        return $importMap;
    }

    private function sha384Of(string $content): string
    {
        return 'sha384-'.base64_encode(hash('sha384', $content, true));
    }

    private function importMapPinning(string $version): void
    {
        new Filesystem()->dumpFile($this->directory.'/importmap.php', '<?php return '.var_export(['@hotwired/turbo' => ['version' => $version]], true).';');
    }

    private function runTheUpdateCommandWithTheNewerVersionPublishedSecondsAgo(int $seconds): void
    {
        $clock = new MockClock(self::NOW);
        $now = $clock->now();
        $registry = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([
            'dist-tags' => ['latest' => self::NEWER],
            'versions' => [self::PINNED => [], self::NEWER => []],
            'time' => [
                self::PINNED => '2025-10-01T00:00:00.000Z',
                self::NEWER => $now->sub(new DateInterval('PT'.$seconds.'S'))->format(DATE_RFC3339_EXTENDED),
            ],
        ]));
        $configReader = $this->configReader();
        $downloader = new RemotePackageDownloader(new RemotePackageStorage($this->directory.'/assets/vendor'), $configReader, $this->offlineResolver());
        $manager = new ImportMapManager(
            self::createStub(AssetMapperInterface::class),
            $configReader,
            $downloader,
            $this->offlineResolver(),
            new ImportMapUpdateChecker($configReader, $registry, $clock, $this->wiredMinimumReleaseAge()),
        );

        $tester = new CommandTester(new ImportMapUpdateCommand($manager, new ImportMapVersionChecker($configReader, $downloader)));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    private function offlineResolver(): PackageResolverInterface
    {
        $resolver = self::createStub(PackageResolverInterface::class);
        $resolver->method('resolvePackages')->willReturnCallback(static fn (array $packages): array => array_map(
            static fn (mixed $package): ResolvedImportMapPackage => $package instanceof PackageRequireOptions
                ? new ResolvedImportMapPackage($package, (string) $package->versionConstraint, ImportMapType::JS)
                : self::fail('the update asked to resolve something other than package options'),
            $packages,
        ));
        $resolver->method('downloadPackages')->willReturnCallback(static fn (array $entries): array => array_map(
            static fn (): array => ['content' => self::MODULE, 'dependencies' => [], 'extraFiles' => []],
            $entries,
        ));

        return $resolver;
    }

    private function configReader(): ImportMapConfigReader
    {
        return new ImportMapConfigReader($this->directory.'/importmap.php', new RemotePackageStorage($this->directory.'/assets/vendor'));
    }

    private function pinnedVersion(): ?string
    {
        return $this->configReader()->getEntries()->get('@hotwired/turbo')->version;
    }
}
