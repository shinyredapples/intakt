<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\CliManifest;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CliManifest::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class CliManifestTest extends TestCase
{
    private string $manifest = '';

    public function testEachDependencyIsInstalledAtItsExactVersion(): void
    {
        $this->givenTheManifest('{"dependencies": {"@scope/tool": "1.2.3", "other": "0.10.0-beta.2"}}');

        self::assertSame(['@scope/tool@1.2.3', 'other@0.10.0-beta.2'], CliManifest::installArgumentsFrom($this->manifest));
    }

    public function testAManifestThatCannotBeReadIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::unusableCliManifest($this->manifest, 'it cannot be read'));

        CliManifest::installArgumentsFrom($this->manifest);
    }

    public function testAManifestThatIsNoJsonIsRefused(): void
    {
        $this->givenTheManifest('{"dependencies": ');

        $this->expectExceptionObject(JobImageNotBuilt::unusableCliManifest($this->manifest, 'it is not valid JSON: Syntax error'));

        CliManifest::installArgumentsFrom($this->manifest);
    }

    #[DataProvider('withoutDependencies')]
    public function testAManifestWithoutDependenciesIsRefused(string $json): void
    {
        $this->givenTheManifest($json);

        $this->expectExceptionObject(JobImageNotBuilt::unusableCliManifest($this->manifest, 'it names no dependencies'));

        CliManifest::installArgumentsFrom($this->manifest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function withoutDependencies(): iterable
    {
        yield 'no key' => ['{"name": "tools"}'];
        yield 'empty' => ['{"dependencies": {}}'];
        yield 'not an object' => ['{"dependencies": "tool"}'];
        yield 'not an object at the top' => ['"tool"'];
    }

    #[DataProvider('notExact')]
    public function testADependencyWithoutAnExactVersionIsRefused(mixed $version): void
    {
        $this->givenTheManifest(json_encode(['dependencies' => ['tool' => $version]], JSON_THROW_ON_ERROR));

        $this->expectExceptionObject(JobImageNotBuilt::unusableCliManifest($this->manifest, 'tool has no exact version'));

        CliManifest::installArgumentsFrom($this->manifest);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notExact(): iterable
    {
        yield 'caret range' => ['^1.2.3'];
        yield 'tilde range' => ['~1.2.3'];
        yield 'two numbers' => ['1.2'];
        yield 'tag' => ['latest'];
        yield 'url' => ['https://example.com/tool.tgz'];
        yield 'shell word' => ['1.2.3; id'];
        yield 'trailing newline' => ["1.2.3\n"];
        yield 'not text' => [123];
    }

    #[DataProvider('notPackageNames')]
    public function testADependencyThatIsNoNpmPackageNameIsRefused(string $package): void
    {
        $this->givenTheManifest(json_encode(['dependencies' => [$package => '1.2.3']], JSON_THROW_ON_ERROR));

        $this->expectExceptionObject(JobImageNotBuilt::unusableCliManifest($this->manifest, sprintf('"%s" is not an npm package name', $package)));

        CliManifest::installArgumentsFrom($this->manifest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPackageNames(): iterable
    {
        yield 'shell word' => ['tool;id'];
        yield 'uppercase' => ['Tool'];
        yield 'scope without name' => ['@scope/'];
        yield 'number' => ['7'];
        yield 'trailing newline' => ["tool\n"];
    }

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir().'/intakt-cli-manifest-'.bin2hex(random_bytes(4)).'/package.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(dirname($this->manifest));
    }

    private function givenTheManifest(string $json): void
    {
        new Filesystem()->dumpFile($this->manifest, $json);
    }
}
