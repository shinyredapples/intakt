<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Psr\Container\ContainerInterface as PsrContainer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class AskedServiceIds
{
    private const array CONTAINERS = [ContainerInterface::class, PsrContainer::class, AbstractController::class];

    // because a locator answers for the local keys it was given and a container bag for parameter names, not for container ids
    private const array OWN_KEYS = [ServiceProviderInterface::class, ContainerBagInterface::class];

    // because ServiceMap::getServiceIdFromNode() gives up on a union of constant ids and would let a misspelled branch through
    /**
     * @return list<string>
     */
    public function of(MethodCall $call, Scope $scope, string $method): array
    {
        $argument = $this->idArgument($call);

        if (!$call->name instanceof Identifier || $method !== $call->name->toLowerString() || !$argument instanceof Arg || !$this->isContainer($scope->getType($call->var))) {
            return [];
        }

        return array_map(static fn (ConstantStringType $id): string => $id->getValue(), $scope->getType($argument->value)->getConstantStrings());
    }

    // because named arguments may put invalidBehavior: before id:, so the id is not always the first argument
    private function idArgument(MethodCall $call): ?Arg
    {
        $first = $call->getArgs()[0] ?? null;

        if (null === $first || null === $first->name) {
            return $first;
        }

        return array_find($call->getArgs(), static fn (Arg $argument): bool => 'id' === $argument->name?->toString());
    }

    private function isContainer(Type $receiver): bool
    {
        if (array_any(self::OWN_KEYS, static fn (string $keyed): bool => new ObjectType($keyed)->isSuperTypeOf($receiver)->yes())) {
            return false;
        }

        return array_any(self::CONTAINERS, static fn (string $container): bool => new ObjectType($container)->isSuperTypeOf($receiver)->yes());
    }
}
