<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Build\Infrastructure\BuildRoutingConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(BuildRoutingConfiguration::class)]
final class BuildRoutingConfigurationTest extends TestCase
{
    public function testAProjectWithoutABuildSectionRoutesToTheStandardTierWithNoSensitivePaths(): void
    {
        $build = $this->process(['alpha' => []])['alpha']['build'];

        self::assertSame(['tier' => 'standard', 'sensitive_paths' => []], $build);
    }

    public function testABuildSectionSetsTheTierAndTheSensitivePaths(): void
    {
        $build = $this->process(['alpha' => ['build' => ['tier' => 'light', 'sensitive_paths' => ['src/**/Security/**']]]])['alpha']['build'];

        self::assertSame(['tier' => 'light', 'sensitive_paths' => ['src/**/Security/**']], $build);
    }

    public function testAnUnknownTierNameIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['tier' => 'gpt-5']]]);
    }

    public function testSensitivePathsGivenAsAMapAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['sensitive_paths' => ['security' => 'src/Security/**']]]]);
    }

    public function testASensitivePathThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['sensitive_paths' => ["src/\xff"]]]]);
    }

    public function testAnEmptySensitivePathIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['sensitive_paths' => ['']]]]);
    }

    public function testAnUnknownBuildKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['model' => 'gpt-5']]]);
    }

    /**
     * @param array<string, mixed> $projects
     *
     * @return array<string, array{build: array{tier: string, sensitive_paths: list<string>}}>
     */
    private function process(array $projects): array
    {
        /* @var array<string, array{build: array{tier: string, sensitive_paths: list<string>}}> */
        return new Processor()->processConfiguration(new BuildRoutingConfiguration(), [['projects' => $projects]])['projects'];
    }
}
