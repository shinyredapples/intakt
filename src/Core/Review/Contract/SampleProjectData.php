<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use Uri\Rfc3986\Uri;

final readonly class SampleProjectData
{
    // Found from this folder instead of the kernel's project directory, because config/packages/ai.php builds the key catalog before the container exists.
    public const string SHIPPED = __DIR__.'/../../../../config/sample-project.yaml';

    public function __construct(
        private string $file = self::SHIPPED,
    ) {
    }

    public function routerUrl(): Uri
    {
        return Uri::parse($this->text('router_url')) ?? throw $this->broken('router_url');
    }

    public function priceDate(): string
    {
        return $this->text('price_date');
    }

    public function model(Provider $provider, Tier $tier): string
    {
        return $this->text('models', $provider->value, $tier->value);
    }

    public function secondLab(): string
    {
        return $this->text('second_lab');
    }

    // Null when the file lists none, because an AI lab's own model has no router provider to swap.
    public function fallbackOf(string $model): ?string
    {
        return null === $this->value('fallbacks', $model) ? null : $this->text('fallbacks', $model);
    }

    public function price(string $model): ModelPrice
    {
        $price = $this->value('prices', $model);
        if (!is_array($price) || [] === $price) {
            throw $this->broken('prices', $model);
        }

        $amounts = ModelPrice::none();
        foreach ($price as $kind => $amount) {
            if (!is_string($kind) || !is_int($amount) && !is_float($amount)) {
                throw $this->broken('prices', $model);
            }

            $amounts = $amounts->with($kind, $amount);
        }

        return $amounts;
    }

    private function text(string ...$path): string
    {
        $value = $this->value(...$path);

        return is_string($value) && '' !== $value ? $value : throw $this->broken(...$path);
    }

    private function value(string ...$path): mixed
    {
        return array_reduce($path, static fn (mixed $node, string $key): mixed => is_array($node) ? $node[$key] ?? null : null, Yaml::parseFile($this->file));
    }

    private function broken(string ...$path): LogicException
    {
        return new LogicException(sprintf('The sample project data file %s has no valid %s.', $this->file, implode('.', $path)));
    }
}
