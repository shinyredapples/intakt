<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use InvalidArgumentException;
use Twig\Attribute\AsTwigFunction;

final readonly class ComponentOptions
{
    // Throws on an unknown name, because without strict variables Twig would render an empty badge or icon instead.
    /**
     * @param array<string, mixed> $options
     */
    #[AsTwigFunction('component_option')]
    public function of(array $options, string $name): mixed
    {
        if (!\array_key_exists($name, $options)) {
            throw new InvalidArgumentException(\sprintf('"%s" is none of %s.', $name, implode(', ', array_keys($options))));
        }

        return $options[$name];
    }
}
