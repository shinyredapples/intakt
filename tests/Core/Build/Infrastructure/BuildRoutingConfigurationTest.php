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
        $build = $this->process(['alpha' => []]);

        self::assertSame(['tier' => 'standard', 'sensitive_paths' => [], 'escalate_on' => ['failed-attempt', 'blocking-finding', 'stall']], $build);
    }

    public function testABuildSectionSetsTheTierAndTheSensitivePaths(): void
    {
        $build = $this->process(['alpha' => ['build' => ['tier' => 'light', 'sensitive_paths' => ['src/**/Security/**']]]]);

        self::assertSame(['tier' => 'light', 'sensitive_paths' => ['src/**/Security/**'], 'escalate_on' => ['failed-attempt', 'blocking-finding', 'stall']], $build);
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

    public function testEscalateOnCanTurnATriggerOff(): void
    {
        $build = $this->process(['alpha' => ['build' => ['escalate_on' => ['stall']]]]);

        self::assertSame(['stall'], $build['escalate_on']);
    }

    public function testANullEscalateOnTurnsEveryEscalationOff(): void
    {
        $build = $this->process(['alpha' => ['build' => ['escalate_on' => null]]]);

        self::assertSame([], $build['escalate_on']);
    }

    public function testAnUnknownSetbackInEscalateOnIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['escalate_on' => ['panic']]]]);
    }

    public function testEscalateOnGivenAsAMapIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['escalate_on' => ['a' => 'stall']]]]);
    }

    public function testAnUnknownBuildKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['alpha' => ['build' => ['model' => 'gpt-5']]]);
    }

    /**
     * @param array<string, mixed> $projects
     *
     * @return array<mixed>
     */
    private function process(array $projects): array
    {
        $processed = new Processor()->processConfiguration(new BuildRoutingConfiguration(), [['projects' => $projects]])['projects'];
        assert(is_array($processed) && is_array($processed['alpha']) && is_array($processed['alpha']['build']));

        return $processed['alpha']['build'];
    }
}
