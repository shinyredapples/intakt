<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Rule<InClassNode>
 */
final readonly class ContractArrayTypeRule implements Rule
{
    private const string CONTRACT_FOLDER = '~^src/[^/]+/[^/]+/Contract/~';

    private const string HOW_TO_COMPLY = ': a module contract takes and returns typed objects, never array shapes, arrays of scalars or untyped arrays; pass a readonly value object, or a list<Object> or array<string, Object> of objects or enums.';

    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isContract($scope->getFile())) {
            return [];
        }

        $class = $node->getClassReflection();
        $declaration = $node->getOriginalNode();
        $findings = [];

        foreach (array_filter($declaration->getMethods(), static fn (ClassMethod $method): bool => $method->isPublic()) as $method) {
            $findings = [...$findings, ...$this->signatureFindings($method, $class)];
        }

        return [...$findings, ...$this->propertyFindings($declaration, $class)];
    }

    private function isContract(string $file): bool
    {
        return str_starts_with($file, $this->root.'/') && 1 === preg_match(self::CONTRACT_FOLDER, substr($file, strlen($this->root) + 1));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function signatureFindings(ClassMethod $method, ClassReflection $class): array
    {
        $name = sprintf('Method %s::%s()', $class->getDisplayName(), $method->name->toString());
        // a promoted public property is skipped here because its property finding already reports the same type on the same line
        $promoted = $this->isConstructor($method) ? array_keys($this->promotedPublicNamesOf($method)) : [];
        $typed = [];

        foreach ($class->getNativeMethod($method->name->toString())->getVariants() as $variant) {
            $typed = [...$typed, ...$this->parameterTypes($variant, $name, $promoted), [$name.' returns', $variant->getReturnType()]];
        }

        return $this->findingsOf($typed, $method->getStartLine());
    }

    private function isConstructor(ClassMethod $method): bool
    {
        return '__construct' === $method->name->toLowerString();
    }

    /**
     * @return array<string, int>
     */
    private function promotedPublicNamesOf(ClassMethod $constructor): array
    {
        $names = [];

        foreach (array_filter($constructor->params, static fn (Param $param): bool => $param->isPromoted() && $param->isPublic()) as $param) {
            $names[$this->parameterName($param)] = $param->getStartLine();
        }

        return $names;
    }

    private function parameterName(Param $param): string
    {
        return $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '';
    }

    /**
     * @param list<string> $skipped
     *
     * @return list<array{string, Type}>
     */
    private function parameterTypes(ParametersAcceptor $variant, string $method, array $skipped): array
    {
        $kept = array_filter($variant->getParameters(), static fn (ParameterReflection $parameter): bool => !in_array($parameter->getName(), $skipped, true));

        return array_values(array_map(static fn (ParameterReflection $parameter): array => [sprintf('%s parameter $%s is typed', $method, $parameter->getName()), $parameter->getType()], $kept));
    }

    /**
     * @param list<array{string, Type}> $typed
     *
     * @return list<IdentifierRuleError>
     */
    private function findingsOf(array $typed, int $line): array
    {
        $refused = array_filter($typed, fn (array $labeled): bool => $this->isRefused($labeled[1]));

        return array_values(array_map(fn (array $labeled): IdentifierRuleError => $this->finding($labeled[0].' '.$this->described($labeled[1]), $line), $refused));
    }

    // a nullable or union type crosses the boundary in each of its members, so each member is judged on its own
    private function isRefused(Type $type): bool
    {
        if ($type instanceof UnionType) {
            return array_any($type->getTypes(), $this->isRefused(...));
        }

        return $type->isArray()->yes() && !$this->isListOrStringMapOfObjects($type);
    }

    private function isListOrStringMapOfObjects(Type $array): bool
    {
        if ($array->isConstantArray()->yes()) {
            return false;
        }

        $keyedAllowed = $array->isList()->yes() || $array->getIterableKeyType()->isString()->yes();

        return $keyedAllowed && $array->getIterableValueType()->isObject()->yes();
    }

    private function described(Type $type): string
    {
        return $type->describe(VerbosityLevel::precise());
    }

    private function finding(string $what, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($what.self::HOW_TO_COMPLY)->identifier('intakt.contractArrayType')->line($line)->build();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function propertyFindings(ClassLike $declaration, ClassReflection $class): array
    {
        $constructor = array_find($declaration->getMethods(), $this->isConstructor(...));
        $findings = [];

        foreach ($constructor instanceof ClassMethod ? $this->promotedPublicNamesOf($constructor) : [] as $name => $line) {
            $type = $class->getNativeProperty($name)->getReadableType();

            if ($this->isRefused($type)) {
                $findings[] = $this->finding(sprintf('Property %s::$%s is typed %s', $class->getDisplayName(), $name, $this->described($type)), $line);
            }
        }

        return $findings;
    }
}
