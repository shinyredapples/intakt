<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\EnumCaseReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * @implements Rule<String_>
 */
final readonly class ReSpelledNameRule implements Rule
{
    private const string SERVICE_ID_PREFIX = 'intakt.';

    // not tests/, because tests pin a contract with the literal on purpose, and not config/gates/, because its intakt.* values are PHPStan error identifiers
    private const array CHECKED = ['/src/', '/config/'];

    private const string UNCHECKED = '/config/gates/';

    public function __construct(
        private string $root,
        private ReflectionProvider $reflections,
        private string $provider,
    ) {
    }

    public function getNodeType(): string
    {
        return String_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isChecked($scope->getFile())) {
            return [];
        }

        $message = match (true) {
            $this->isServiceId($node->value) && !$this->isInIdsClass($scope) => "'%s' re-spells a container id or parameter name: use the constant of the module's ids class (a *Services class in its Contract/).",
            $this->isProviderValue($node->value) && !$this->isInProvider($scope) => "'%s' re-spells a Provider case value: derive the name from the case.",
            default => null,
        };

        if (null === $message) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf($message, $node->value))->identifier('intakt.reSpelledName')->build()];
    }

    private function isChecked(string $file): bool
    {
        return array_any(self::CHECKED, fn (string $folder): bool => str_starts_with($file, $this->root.$folder))
            && !str_starts_with($file, $this->root.self::UNCHECKED);
    }

    private function isServiceId(string $value): bool
    {
        return str_starts_with($value, self::SERVICE_ID_PREFIX);
    }

    private function isInIdsClass(Scope $scope): bool
    {
        $class = $scope->getClassReflection()?->getName() ?? '';

        return str_contains($class, '\\Contract\\') && str_ends_with($class, 'Services');
    }

    private function isProviderValue(string $value): bool
    {
        return in_array($value, $this->providerValues(), true);
    }

    private function isInProvider(Scope $scope): bool
    {
        return $scope->getClassReflection()?->getName() === $this->provider;
    }

    /**
     * @return list<string>
     */
    private function providerValues(): array
    {
        $cases = $this->reflections->getClass($this->provider)->getEnumCases();
        $values = array_map(static fn (EnumCaseReflection $case): array => $case->getBackingValueType()?->getConstantStrings() ?? [], array_values($cases));

        return array_map(static fn (ConstantStringType $value): string => $value->getValue(), array_merge(...$values));
    }
}
