<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

final readonly class PrometheusSamples
{
    private const string SAMPLE = '/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{.*\})?[ \t]+(\S+)(?:[ \t]+-?\d+)?$/';

    /**
     * @param array<string, float> $totals
     */
    private function __construct(
        private array $totals,
    ) {
    }

    public static function fromText(string $text): ?self
    {
        $totals = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (1 !== preg_match(self::SAMPLE, $line, $sample) || !is_numeric($sample[2])) {
                return null;
            }
            $totals[$sample[1]] = ($totals[$sample[1]] ?? 0.0) + (float) $sample[2];
        }

        return new self($totals);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->totals);
    }

    // FrankenPHP leaves a labeled series out until it first counts, so an absent one is zero.
    public function sum(string $name): int
    {
        return (int) round($this->totals[$name] ?? 0.0);
    }
}
