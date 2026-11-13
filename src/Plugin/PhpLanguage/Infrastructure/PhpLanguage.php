<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Infrastructure;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\Language;
use Intakt\Plugin\PhpLanguage\Contract\PhpLanguageServices;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\TrustedPackages;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTags;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PhpLanguage implements Language
{
    private ComposerPolicy $policy;

    /**
     * @param list<string> $trustedVendors
     * @param list<string> $packageTypes
     */
    public function __construct(
        UpstreamTags $tags,
        #[Autowire(param: PhpLanguageServices::TRUSTED_VENDORS)]
        array $trustedVendors,
        #[Autowire(param: PhpLanguageServices::PACKAGE_TYPES)]
        array $packageTypes,
    ) {
        $this->policy = new ComposerPolicy(new TrustedPackages($trustedVendors, $packageTypes), $tags);
    }

    public function dependencyFiles(): array
    {
        return [ComposerState::MANIFEST, ComposerState::LOCK];
    }

    #[NoDiscard]
    public function decideDependencies(DependencyFiles $old, DependencyFiles $new): DependencyDecision
    {
        return $this->policy->decide($old, $new);
    }
}
