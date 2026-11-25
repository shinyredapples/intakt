<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\EnumCaseReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * @implements Rule<FileNode>
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

    // the whole file rather than each literal, because PHPStan never analyses the body of a trait that no class uses
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isChecked($scope->getFile())) {
            return [];
        }

        $owners = $this->declaringClassesOf($node->getNodes());
        $findings = [];

        foreach (new NodeFinder()->find($node->getNodes(), $this->isLiteral(...)) as $literal) {
            $message = $this->messageFor($literal, $owners[spl_object_id($literal)] ?? null);

            if (null !== $message) {
                $findings[] = RuleErrorBuilder::message($message)->line($literal->getStartLine())->identifier('intakt.reSpelledName')->build();
            }
        }

        return $findings;
    }

    private function isChecked(string $file): bool
    {
        return array_any(self::CHECKED, fn (string $folder): bool => str_starts_with($file, $this->root.$folder))
            && !str_starts_with($file, $this->root.self::UNCHECKED);
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<int, ClassLike>
     */
    private function declaringClassesOf(array $nodes): array
    {
        $finder = new NodeFinder();
        $owners = [];

        foreach ($finder->findInstanceOf($nodes, ClassLike::class) as $class) {
            // an anonymous class has no namespacedName at all (the name resolver sets it only for a named class), so its literals keep the class around it as their owner
            if (!$class->name instanceof Identifier) {
                continue;
            }

            foreach ($finder->find([$class], $this->isLiteral(...)) as $literal) {
                $owners[spl_object_id($literal)] = $class;
            }
        }

        return $owners;
    }

    private function isLiteral(Node $node): bool
    {
        return $node instanceof String_ || $node instanceof InterpolatedString;
    }

    private function messageFor(Node $literal, ?ClassLike $owner): ?string
    {
        $value = $this->leadingTextOf($literal);

        return match (true) {
            $this->isServiceId($value) && !$this->isIdsClass($owner) => sprintf("'%s' re-spells a container id or parameter name: use the constant of the module's ids class (a *Services class in its Contract/).", $value),
            $literal instanceof String_ && $this->isProviderValue($value) && $this->nameOf($owner) !== $this->provider => sprintf("'%s' re-spells a Provider case value: derive the name from the case.", $value),
            default => null,
        };
    }

    private function leadingTextOf(Node $literal): string
    {
        if ($literal instanceof String_) {
            return $literal->value;
        }

        $first = $literal instanceof InterpolatedString ? $literal->parts[0] ?? null : null;

        return $first instanceof InterpolatedStringPart ? $first->value : '';
    }

    private function isServiceId(string $value): bool
    {
        return str_starts_with($this->withoutReferenceSign($value), self::SERVICE_ID_PREFIX);
    }

    // one sign only, because '%%' is Symfony's escaped percent and stands for no parameter
    private function withoutReferenceSign(string $value): string
    {
        return str_starts_with($value, '%') || str_starts_with($value, '@') ? substr($value, 1) : $value;
    }

    // only a class, because a trait, interface or enum with an ids class's name would let any class re-spell the ids through it
    private function isIdsClass(?ClassLike $owner): bool
    {
        $name = $this->nameOf($owner);

        return $owner instanceof Class_ && str_contains($name, '\\Contract\\') && str_ends_with($name, 'Services');
    }

    private function isProviderValue(string $value): bool
    {
        return in_array($value, $this->providerValues(), true);
    }

    private function nameOf(?ClassLike $owner): string
    {
        return $owner?->namespacedName?->toString() ?? '';
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
