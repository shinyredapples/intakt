<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use PHPUnit\Framework\Assert;
use ReflectionObject;
use UnitEnum;

// The gate on prompt fencing: it takes the plants from every string of the input itself, so a new field cannot slip past a list typed beside it.
final readonly class FencedPlants
{
    public const string PREFIX = 'PLANT-';

    private const string FENCE = '/<<<UNTRUSTED ([a-z ]+) (fence-[0-9a-f]{16})>>>\n(.*?)\n<<<END \2>>>/s';

    public static function assertEveryPlantIsOnlyFenced(object $input, string $prompt): void
    {
        $outside = self::outsideFences($prompt);

        foreach (self::plantsIn($input) as $plant) {
            Assert::assertStringStartsWith(self::PREFIX, $plant, 'Every text of the input must be a plant, so the gate can find it.');
            Assert::assertStringContainsString($plant, $prompt, 'A plant is missing from the prompt.');
            Assert::assertStringNotContainsString($plant, $outside, 'A plant reaches the prompt outside a fence.');
            self::assertNoLineIsOutside($plant, $outside);
        }
    }

    public static function sourceAround(string $plant, string $prompt): ?UntrustedSource
    {
        foreach (self::realFencesIn($prompt) as [$fenced, $source, $content]) {
            if (str_contains($content, $plant)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function plantsIn(mixed $value): array
    {
        return match (true) {
            is_string($value) => [$value],
            is_array($value) => array_merge([], ...array_map(self::plantsIn(...), array_values($value))),
            $value instanceof UnitEnum, !is_object($value) => [],
            default => self::plantsInProperties($value),
        };
    }

    /**
     * @return list<string>
     */
    private static function plantsInProperties(object $value): array
    {
        $properties = new ReflectionObject($value)->getProperties();

        return array_merge([], ...array_map(static fn ($property): array => self::plantsIn($property->getValue($value)), $properties));
    }

    private static function outsideFences(string $prompt): string
    {
        foreach (self::realFencesIn($prompt) as [$fenced]) {
            $prompt = str_replace($fenced, '', $prompt);
        }

        return $prompt;
    }

    // A part of a plant can leak on its own (a task's first line used as a title), so each line, without the "\r" a copied title drops, is checked too.
    private static function assertNoLineIsOutside(string $plant, string $outside): void
    {
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $plant)) as $line) {
            if ('' !== trim($line)) {
                Assert::assertStringNotContainsString($line, $outside, 'A line of a plant reaches the prompt outside a fence.');
            }
        }
    }

    // Only a fence whose boundary is the one its text gets counts, so a forged fence in the prompt hides nothing.
    /**
     * @return list<array{string, UntrustedSource, string}>
     */
    private static function realFencesIn(string $prompt): array
    {
        preg_match_all(self::FENCE, $prompt, $matches, PREG_SET_ORDER);
        $fences = [];

        foreach ($matches as [$fenced, $name, , $content]) {
            $source = UntrustedSource::tryFrom($name);

            if (null !== $source && new Fence()->contentOf($source, $fenced) === $content) {
                $fences[] = [$fenced, $source, $content];
            }
        }

        return $fences;
    }
}
