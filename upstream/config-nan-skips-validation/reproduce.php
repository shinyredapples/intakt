<?php

declare(strict_types=1);

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class Ceiling implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('root');
        $tree->getRootNode()->children()->floatNode('ceiling')->min(1)->max(100)
            ->validate()->always(static fn (): never => throw new InvalidConfigurationException('validation closure ran'));

        return $tree;
    }
}

try {
    $processed = new Processor()->processConfiguration(new Ceiling(), [['ceiling' => NAN]]);
    echo 'NaN passed min, max and the validation closure: ', var_export($processed['ceiling'], true), PHP_EOL;

    exit(1);
} catch (InvalidConfigurationException $exception) {
    echo $exception->getMessage(), PHP_EOL;
}

echo 'REPRODUCTION PASSES', PHP_EOL;
