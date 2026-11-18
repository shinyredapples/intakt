<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\UsageWindow;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\FloatNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; the windows are named as UsageWindow names them.
final class BudgetConfiguration implements ConfigurationInterface
{
    public const float DEFAULT_CEILING = 90.0;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $budget = $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('budget')->addDefaultsIfNotSet()->children();
        $this->windows($budget->arrayNode('ceilings'), self::DEFAULT_CEILING);
        $this->prices($budget->arrayNode('prices'));
        // The day the list was read, because an estimate is only as good as its list. Only a quoted day is a string: Symfony YAML turns an unquoted date into a timestamp and moves an impossible day such as 2026-02-30 into the next month, so the day the owner wrote is lost.
        $budget->scalarNode('price_date')->defaultNull()
            ->validate()->ifTrue(static fn (mixed $date): bool => null !== $date && !self::isDay($date))->thenInvalid('The price_date must be a date written as YYYY-MM-DD in quotes, such as \'2026-11-13\'.');

        return $tree;
    }

    private function windows(ArrayNodeDefinition $node, float $default): void
    {
        $windows = $node->addDefaultsIfNotSet()->normalizeKeys(false)->children();

        foreach (UsageWindow::cases() as $window) {
            // NaN becomes a string because Symfony Config before 8.2.0-BETA2 skips every check for NaN (upstream/config-nan-skips-validation); ints become floats for the guard.
            $windows->floatNode($window->value)->min(1)->max(100)->defaultValue($default)
                ->beforeNormalization()->ifTrue(static fn (mixed $ceiling): bool => is_float($ceiling) && is_nan($ceiling))->then(static fn (): string => 'NaN')->end()
                ->validate()->always(static fn (int|float $ceiling): float => (float) $ceiling);
        }
    }

    // Keyed by the model name exactly as a tier names it, so a usage row's model finds its price.
    private function prices(ArrayNodeDefinition $node): void
    {
        $price = $node->normalizeKeys(false)->useAttributeAsKey('model')->arrayPrototype();
        $amounts = $price->children();
        $this->amount($amounts, 'input')->isRequired();
        $this->amount($amounts, 'output')->isRequired();
        $this->amount($amounts, 'cache_read');
        $this->amount($amounts, 'cache_write');
        // A provider without cache prices bills cached tokens as input, so the input price stands in for a cache price left out.
        $price->validate()->always(static fn (array $amounts): array => $amounts + ['cache_read' => $amounts['input'], 'cache_write' => $amounts['input']]);
    }

    private static function isDay(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return false !== $day && $day->format('Y-m-d') === $date;
    }

    private function amount(NodeBuilder $amounts, string $name): FloatNodeDefinition
    {
        // A float that is not finite becomes a string so the type check refuses it, because Symfony Config before 8.2.0-BETA2 skips every check for NaN and no maximum stops INF.
        $amount = $amounts->floatNode($name)->min(0);
        $amount->beforeNormalization()->ifTrue(static fn (mixed $value): bool => is_float($value) && !is_finite($value))->then(static fn (mixed $value): string => var_export($value, true))->end()
            ->validate()->always(static fn (int|float $value): float => (float) $value);

        return $amount;
    }
}
