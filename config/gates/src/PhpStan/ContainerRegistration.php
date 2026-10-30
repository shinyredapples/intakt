<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\MappedSuperclass;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ClassReflection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

final readonly class ContainerRegistration
{
    // services.yaml registers src/ and nothing else, so a class elsewhere is never a service: https://symfony.com/doc/current/service_container.html#importing-many-services-at-once-with-resource
    private const string REGISTERED_RESOURCE = '/src/';

    // inside src/ only what the container skips is not a service, so these attributes exempt a class
    private const array NOT_REGISTERED = [
        Exclude::class,
        Entity::class,
        Embeddable::class,
        MappedSuperclass::class,
    ];

    public function __construct(private string $root)
    {
    }

    public function covers(string $file): bool
    {
        return str_starts_with($file, $this->root.self::REGISTERED_RESOURCE);
    }

    public function excludes(ClassReflection $class): bool
    {
        return array_any($class->getAttributes(), static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::NOT_REGISTERED, true));
    }
}
