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
use PHPStan\Symfony\ParameterDefinition;
use PHPStan\Symfony\ParameterMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;

// phpstan-symfony types a known parameter but has no rule for an unknown one, which would throw on the first request
/**
 * @implements Rule<MethodCall>
 */
final readonly class ContainerUnknownParameterRule implements Rule
{
    private const array CONTAINERS = [ContainerInterface::class, AbstractController::class];

    public function __construct(private ParameterMap $parameters)
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
        $unknown = array_filter($this->readNames($node, $scope), fn (string $name): bool => !$this->parameters->getParameter($name) instanceof ParameterDefinition);

        return array_values(array_map(
            static fn (string $name): IdentifierRuleError => RuleErrorBuilder::message(sprintf('Parameter "%s" is not defined in the container.', $name))->identifier('intakt.containerUnknownParameter')->build(),
            $unknown,
        ));
    }

    /**
     * @return list<string>
     */
    private function readNames(MethodCall $call, Scope $scope): array
    {
        $argument = $call->getArgs()[0] ?? null;

        if (!$call->name instanceof Identifier || 'getparameter' !== $call->name->toLowerString() || null === $argument || !$this->isContainer($scope->getType($call->var))) {
            return [];
        }

        return array_values($this->parameters::getParameterKeysFromNode($argument->value, $scope));
    }

    private function isContainer(Type $receiver): bool
    {
        return array_any(self::CONTAINERS, static fn (string $container): bool => new ObjectType($container)->isSuperTypeOf($receiver)->yes());
    }
}
