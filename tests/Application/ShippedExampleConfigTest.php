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
        $models = [$this->text($config, 'codex', 'model')];
        foreach ($this->map($config, 'projects') as $project) {
            foreach ($this->map($project, 'tiers') as $tier) {
                $models[] = $this->text($tier, 'model');
                foreach ($this->substitutes($tier) as $substitute) {
                    $models[] = $this->text($substitute, 'model');
                }
            }
        }

        $unpriced = array_values(array_diff(array_unique($models), array_map(strval(...), array_keys($this->map($config, 'budget', 'prices')))));

        self::assertSame([], $unpriced);
    }

    public function testTheShippedExamplePricesItsOpenAiModelsAtTheSampleProjectsListPrices(): void
    {
        $example = $this->example();
        $sample = $this->sample();

        foreach (['gpt-6.2-sol', 'gpt-6.1-terra', 'gpt-6-luna'] as $model) {
            self::assertSame($this->at($sample, 'prices', $model), $this->at($example, 'budget', 'prices', $model), $model);
        }
        self::assertSame(['input' => 2, 'output' => 10], $this->at($example, 'budget', 'prices', 'gpt-6.2-sol'));
        self::assertSame(['input' => 1, 'output' => 5], $this->at($example, 'budget', 'prices', 'gpt-6.1-terra'));
    }

    public function testTheShippedExampleMapsTheBetaLightTierToGpt6LunaOnOpenAi(): void
    {
        self::assertSame(
            ['provider' => 'openai', 'model' => 'gpt-6-luna'],
            $this->at($this->example(), 'projects', 'beta', 'tiers', 'light'),
        );
    }

    public function testTheShippedExampleMapsTheTiersOfBothProjectsAndPinsCodexToTheShippedModels(): void
    {
        $config = $this->example();

        self::assertSame(
            [
                'strong' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5'],
                'standard' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
                'light' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            ],
            $this->at($config, 'projects', 'alpha', 'tiers'),
        );
        self::assertSame(
            [
                'strong' => ['provider' => 'openai', 'model' => 'gpt-6.2-sol'],
                'standard' => ['provider' => 'openai', 'model' => 'gpt-6.1-terra'],
                'light' => ['provider' => 'openai', 'model' => 'gpt-6-luna'],
            ],
            $this->at($config, 'projects', 'beta', 'tiers'),
        );
        self::assertSame('gpt-6.2-sol', $this->at($config, 'codex', 'model'));
    }

    public function testTheShippedExampleAndTheSampleProjectCarryTheSamePriceDate(): void
    {
        self::assertSame($this->at($this->sample(), 'price_date'), $this->at($this->example(), 'budget', 'price_date'));
    }

    private function example(): mixed
    {
        return Yaml::parseFile($this->root().'/config/intakt.example.yaml');
    }

    private function text(mixed $value, string ...$path): string
    {
        $text = $this->at($value, ...$path);
        self::assertIsString($text);

        return $text;
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $value, string ...$path): array
    {
        $map = $this->at($value, ...$path);
        self::assertIsArray($map);

        return $map;
    }

    /** @return array<array-key, mixed> */
    private function substitutes(mixed $tier): array
    {
        self::assertIsArray($tier);
        $substitutes = $tier['substitutes'] ?? [];
        self::assertIsArray($substitutes);

        return $substitutes;
    }

    private function sample(): mixed
    {
        return Yaml::parseFile($this->root().'/config/sample-project.yaml');
    }

    private function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
