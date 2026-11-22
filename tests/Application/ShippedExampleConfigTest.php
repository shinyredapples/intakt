<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// Because an example that names a model without a price shows an unknown cost for every review that uses it.
#[CoversNothing]
final class ShippedExampleConfigTest extends TestCase
{
    public function testEveryModelTheShippedExampleMapsOrPinsHasAPriceInTheExample(): void
    {
        $config = $this->example();
        $models = [$config['codex']['model']];
        foreach ($config['projects'] as $project) {
            foreach ($project['tiers'] as $tier) {
                $models[] = $tier['model'];
                foreach ($tier['substitutes'] ?? [] as $substitute) {
                    $models[] = $substitute['model'];
                }
            }
        }

        $unpriced = array_values(array_diff(array_unique($models), array_keys($config['budget']['prices'])));

        self::assertSame([], $unpriced);
    }

    public function testTheShippedExamplePricesItsOpenAiModelsAtTheSampleProjectsListPrices(): void
    {
        $example = $this->example()['budget']['prices'];
        $sample = Yaml::parseFile($this->root().'/config/sample-project.yaml')['prices'];

        foreach (['gpt-6.2-sol', 'gpt-6.1-terra', 'gpt-6-luna'] as $model) {
            self::assertSame($sample[$model], $example[$model], $model);
        }
        self::assertSame(['input' => 2, 'output' => 10], $example['gpt-6.2-sol']);
        self::assertSame(['input' => 1, 'output' => 5], $example['gpt-6.1-terra']);
    }

    public function testTheShippedExampleMapsTheBetaLightTierToGpt6LunaOnOpenAi(): void
    {
        self::assertSame(
            ['provider' => 'openai', 'model' => 'gpt-6-luna'],
            $this->example()['projects']['beta']['tiers']['light'],
        );
    }

    public function testTheShippedExampleAndTheSampleProjectCarryTheSamePriceDate(): void
    {
        $sample = Yaml::parseFile($this->root().'/config/sample-project.yaml');

        self::assertSame($sample['price_date'], $this->example()['budget']['price_date']);
    }

    /** @return array<string, mixed> */
    private function example(): array
    {
        return Yaml::parseFile($this->root().'/config/intakt.example.yaml');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
