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
            $value = 1 === preg_match(self::SAMPLE, $line, $sample) ? self::value($sample[2]) : null;
            if (null === $value) {
                return null;
            }
            $totals[$sample[1]] = ($totals[$sample[1]] ?? 0.0) + $value;
        }

        return new self($totals);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->totals);
    }

    // FrankenPHP leaves a labeled series out until it first counts, so an absent one is zero.
    public function sum(string ...$names): ?int
    {
        $total = round(array_sum(array_map(fn (string $name): float => $this->totals[$name] ?? 0.0, $names)));

        return $this->fitsInt($total) ? (int) $total : null;
    }

    // PHP_INT_MAX turns into 2^63 as a float, so the upper bound must be strict against -PHP_INT_MIN.
    private function fitsInt(float $total): bool
    {
        return $total >= (float) PHP_INT_MIN && $total < -(float) PHP_INT_MIN;
    }

    // Accepted because the text format allows them, so an unrelated series cannot hide the counted ones.
    private static function value(string $text): ?float
    {
        return match (strtolower($text)) {
            'nan' => NAN,
            'inf', '+inf' => INF,
            '-inf' => -INF,
            default => is_numeric($text) ? (float) $text : null,
        };
    }
}
