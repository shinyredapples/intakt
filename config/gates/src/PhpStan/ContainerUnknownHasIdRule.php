<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;

// phpstan-symfony reports an unknown id in get() only, so a misspelled has() would quietly answer false at runtime
/**
 * @implements Rule<MethodCall>
 */
final readonly class ContainerUnknownHasIdRule implements Rule
{
    public function __construct(private ServiceMap $services, private AskedServiceIds $askedIds)
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
        $unknown = array_filter($this->askedIds->of($node, $scope, 'has'), fn (string $id): bool => !$this->services->getService($id) instanceof ServiceDefinition);

        return array_values(array_map(
            static fn (string $id): IdentifierRuleError => RuleErrorBuilder::message(sprintf('Service "%s" is not registered in the container.', $id))->identifier('intakt.containerUnknownHasId')->build(),
            $unknown,
        ));
    }
}
