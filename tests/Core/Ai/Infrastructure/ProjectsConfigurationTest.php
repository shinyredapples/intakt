<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ProjectsConfiguration::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
final class ProjectsConfigurationTest extends TestCase
{
    public function testProjectsKeepTheirTierMappings(): void
    {
        $config = $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini']]]]]);

        self::assertSame(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini']]]]], $config);
    }

    public function testNoProjectsIsValid(): void
    {
        self::assertSame(['projects' => []], $this->process([]));
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'nowhere', 'model' => 'm']]]]]);
    }

    public function testAnUnknownTierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['huge' => ['provider' => 'openai', 'model' => 'm']]]]]);
    }

    public function testAMappingWithoutAModelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai']]]]]);
    }

    public function testAProjectNameKeepsItsDashes(): void
    {
        $config = $this->process(['projects' => ['my-project' => ['tiers' => []]]]);

        self::assertSame(['my-project'], array_keys((array) $config['projects']));
    }

    public function testAModelThatIsNoStringIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 5]]]]]);
    }

    public function testAModelMadeOnlyOfSpacesIsRefusedNamingTheKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/The "model" key must not be blank\./');

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => '   ']]]]]);
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>
     */
    private function process(array $contents): array
    {
        /** @var array<string, mixed> $processed */
        $processed = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents]);

        return $processed;
    }
}
