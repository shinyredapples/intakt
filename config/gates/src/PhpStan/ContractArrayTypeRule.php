<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
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

    public function __construct(private string $root, private FileTypeMapper $typeMapper)
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
        $constructor = array_find($declaration->getMethods(), $this->isConstructor(...));
        $promoted = $constructor instanceof ClassMethod ? $this->promotedPublicNamesOf($constructor) : [];
        $findings = [];

        foreach ($this->exposedMethodsOf($class) as $method) {
            $findings = [...$findings, ...$this->signatureFindings($method, $class, $this->lineOf($method, $declaration), $this->isConstructorName($method) ? array_keys($promoted) : [])];
        }

        $inputs = $constructor instanceof ClassMethod ? $this->promotedInputFindings($constructor, $promoted, $class, $scope->getFile()) : [];

        return [...$findings, ...$inputs, ...$this->propertyFindings($promoted, $class)];
    }

    private function isContract(string $file): bool
    {
        return str_starts_with($file, $this->root.'/') && 1 === preg_match(self::CONTRACT_FOLDER, substr($file, strlen($this->root) + 1));
    }

    private function isConstructor(ClassMethod $method): bool
    {
        return $this->isConstructorName($method->name->toString());
    }

    private function isConstructorName(string $method): bool
    {
        return '__construct' === strtolower($method);
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

    // a public method a trait brings becomes the using class's own method, so it crosses the boundary as part of the class
    /**
     * @return list<string>
     */
    private function exposedMethodsOf(ClassReflection $class): array
    {
        $own = array_filter($class->getNativeReflection()->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName());

        return array_values(array_filter(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $own), fn (string $method): bool => !$this->isFixedByBuiltInInterface($method, $class)));
    }

    // PHP itself declares such a method's signature (JsonSerializable::jsonSerialize() returns array), so a contract cannot type it otherwise
    private function isFixedByBuiltInInterface(string $method, ClassReflection $class): bool
    {
        return array_any($class->getInterfaces(), static fn (ClassReflection $interface): bool => $interface->isBuiltin() && $interface->hasNativeMethod($method));
    }

    // a method a trait brings has no line in this file, so it is reported where the class starts
    private function lineOf(string $method, ClassLike $declaration): int
    {
        $declared = $declaration->getMethod($method);

        return $declared instanceof ClassMethod ? $declared->getStartLine() : $declaration->getStartLine();
    }

    /**
     * @param list<string> $promoted
     *
     * @return list<IdentifierRuleError>
     */
    private function signatureFindings(string $method, ClassReflection $class, int $line, array $promoted): array
    {
        $name = sprintf('Method %s::%s()', $class->getDisplayName(), $method);
        $typed = [];

        foreach ($class->getNativeMethod($method)->getVariants() as $variant) {
            $typed = [...$typed, ...$this->parameterTypes($variant, $name, $promoted), [$name.' returns', $variant->getReturnType()]];
        }

        return $this->findingsOf($typed, $line);
    }

    // PHPStan types a promoted parameter like its property, so the property finding reports it on the same line; a differing @param is judged in promotedInputFindings()
    /**
     * @param list<string> $promoted
     *
     * @return list<array{string, Type}>
     */
    private function parameterTypes(ParametersAcceptor $variant, string $method, array $promoted): array
    {
        $kept = array_filter($variant->getParameters(), static fn (ParameterReflection $parameter): bool => !in_array($parameter->getName(), $promoted, true));

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

    // a constructor may take a promoted property in another shape (@param) than it keeps (@var), and callers pass the @param shape
    /**
     * @param array<string, int> $promoted
     *
     * @return list<IdentifierRuleError>
     */
    private function promotedInputFindings(ClassMethod $constructor, array $promoted, ClassReflection $class, string $file): array
    {
        $comment = $constructor->getDocComment();

        if (!$comment instanceof Doc) {
            return [];
        }

        $tags = $this->typeMapper->getResolvedPhpDoc($file, $class->getName(), null, $constructor->name->toString(), $comment->getText())->getParamTags();
        $findings = [];

        foreach (array_intersect_key($tags, $promoted) as $name => $tag) {
            $input = $tag->getType();

            if (!$input->equals($class->getNativeProperty($name)->getReadableType()) && $this->isRefused($input)) {
                $findings[] = $this->finding(sprintf('Method %s::%s() parameter $%s is typed %s', $class->getDisplayName(), $constructor->name->toString(), $name, $this->described($input)), $constructor->getStartLine());
            }
        }

        return $findings;
    }

    /**
     * @param array<string, int> $promoted
     *
     * @return list<IdentifierRuleError>
     */
    private function propertyFindings(array $promoted, ClassReflection $class): array
    {
        $findings = [];

        foreach ($promoted as $name => $line) {
            $type = $class->getNativeProperty($name)->getReadableType();

            if ($this->isRefused($type)) {
                $findings[] = $this->finding(sprintf('Property %s::$%s is typed %s', $class->getDisplayName(), $name, $this->described($type)), $line);
            }
        }

        return $findings;
    }
}
