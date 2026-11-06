<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Psr\Container\ContainerInterface as PsrContainer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

// phpstan-symfony reports an unknown id in get() only, so a misspelled has() would quietly answer false at runtime
/**
 * @implements Rule<MethodCall>
 */
final readonly class ContainerUnknownHasIdRule implements Rule
{
    private const array CONTAINERS = [ContainerInterface::class, PsrContainer::class, AbstractController::class];

    public function __construct(private ServiceMap $services)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $id = $this->askedId($node, $scope);

        if (null === $id || $this->services->getService($id) instanceof ServiceDefinition) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf('Service "%s" is not registered in the container.', $id))->identifier('intakt.containerUnknownHasId')->build()];
    }

    private function askedId(MethodCall $call, Scope $scope): ?string
    {
        $argument = $call->getArgs()[0] ?? null;

        if (!$call->name instanceof Identifier || 'has' !== $call->name->toLowerString() || null === $argument || !$this->isContainer($scope->getType($call->var))) {
            return null;
        }

        return $this->services::getServiceIdFromNode($argument->value, $scope);
    }

    private function isContainer(Type $receiver): bool
    {
        // because a locator answers for the local keys it was given, not for container ids
        if (new ObjectType(ServiceProviderInterface::class)->isSuperTypeOf($receiver)->yes()) {
            return false;
        }

        return array_any(self::CONTAINERS, fn ($container) => new ObjectType($container)->isSuperTypeOf($receiver)->yes());
    }
}
