<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; the rules are the owner's for every project.
final class ComposerPolicyConfiguration implements ConfigurationInterface
{
    public const array DEFAULT_VENDORS = ['symfony', 'doctrine'];

    // Plain libraries and bundles, and the types of the official Symfony AI components; a Composer plugin is never routine, whatever this list holds.
    public const array DEFAULT_TYPES = [
        'library',
        'symfony-bundle',
        'symfony-ai-platform',
        'symfony-ai-store',
        'symfony-ai-message-store',
        'symfony-ai-tool',
        'symfony-ai-mate',
    ];

    // Composer's rule for the vendor part of a package name, so an entry with a slash or in capitals cannot match a package.
    private const string VENDOR = '/\A[a-z0-9]([_.-]?[a-z0-9]+)*\z/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $policy = $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('composer_policy')->addDefaultsIfNotSet()->children();
        $policy->arrayNode('trusted_vendors')->defaultValue(self::DEFAULT_VENDORS)->stringPrototype()
            ->validate()->ifTrue(static fn (string $vendor): bool => 1 !== preg_match(self::VENDOR, $vendor))->thenInvalid('%s is not a vendor name such as "symfony"');
        $policy->arrayNode('package_types')->defaultValue(self::DEFAULT_TYPES)->stringPrototype()->cannotBeEmpty();

        return $tree;
    }
}
