<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionParameter;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\PhpDoc\Tag\ParamTag;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ConditionalType;
use PHPStan\Type\ConditionalTypeForParameter;
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
        $promoted = $this->promotedPublicNamesOf($class);
        $findings = [];

        $constructor = $class->getNativeReflection()->getConstructor();
        $promotedHere = $constructor instanceof ReflectionMethod ? $this->promotedParametersOf($constructor) : [];

        foreach ($this->exposedMethodsOf($class) as $method) {
            $skipped = $this->isConstructor($method) ? $promotedHere : [];
            $findings = [...$findings, ...$this->findingsOf($this->signatureTypes($method, $class, $skipped), $this->lineOf($method, $declaration))];
        }

        return [...$findings, ...$this->promotedInputFindings($class, $declaration, $promoted), ...$this->propertyFindings($promoted, $class, $declaration)];
    }

    private function isContract(string $file): bool
    {
        return str_starts_with($file, $this->root.'/') && 1 === preg_match(self::CONTRACT_FOLDER, substr($file, strlen($this->root) + 1));
    }

    // a promoted property a trait's constructor brings is the using class's own property, so it crosses the boundary as part of the class
    /**
     * @return list<string>
     */
    private function promotedPublicNamesOf(ClassReflection $class): array
    {
        $promoted = array_filter($class->getNativeReflection()->getProperties(ReflectionProperty::IS_PUBLIC), fn (ReflectionProperty $property): bool => $property->isPromoted() && $this->isJudgedHere($property->getDeclaringClass(), $class));

        return array_values(array_map(static fn (ReflectionProperty $property): string => $property->getName(), $promoted));
    }

    // a member PHP or a vendor package declares has a signature this project cannot change, and a member of a parent under Contract/ is judged in the parent's own file
    private function isJudgedHere(ReflectionClass $declaring, ClassReflection $class): bool
    {
        $file = $declaring->getFileName();

        return $declaring->getName() === $class->getName() || (is_string($file) && str_starts_with($file, $this->root.'/src/') && !$this->isContract($file));
    }

    // read from the constructor, not the class's properties, because a parameter that only shares its name with a property a parent promotes is an ordinary parameter
    /**
     * @return list<string>
     */
    private function promotedParametersOf(ReflectionMethod $constructor): array
    {
        $promoted = array_filter($constructor->getParameters(), static fn (ReflectionParameter $parameter): bool => $parameter->isPromoted());

        return array_values(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $promoted));
    }

    // a public method a trait brings becomes the using class's own method, so it crosses the boundary as part of the class, and so does one a parent outside Contract/ declares
    /**
     * @return list<string>
     */
    private function exposedMethodsOf(ClassReflection $class): array
    {
        $exposed = array_filter($class->getNativeReflection()->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $method): bool => $this->isJudgedHere($method->getDeclaringClass(), $class));

        return array_values(array_filter(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $exposed), fn (string $method): bool => !$this->isFixedByBuiltInInterface($method, $class)));
    }

    // PHP itself declares such a method's signature (JsonSerializable::jsonSerialize() returns mixed), so a contract cannot narrow it to a typed object
    private function isFixedByBuiltInInterface(string $method, ClassReflection $class): bool
    {
        return array_any($class->getInterfaces(), static fn (ClassReflection $interface): bool => $interface->isBuiltin() && $interface->hasNativeMethod($method));
    }

    private function isConstructor(string $method): bool
    {
        return '__construct' === strtolower($method);
    }

    /**
     * @param list<string> $promoted
     *
     * @return list<array{string, Type}>
     */
    private function signatureTypes(string $method, ClassReflection $class, array $promoted): array
    {
        $name = sprintf('Method %s::%s()', $class->getDisplayName(), $method);
        $typed = [];

        foreach ($class->getNativeMethod($method)->getVariants() as $variant) {
            $typed = [...$typed, ...$this->parameterTypes($variant, $name, $promoted), [$name.' returns', $variant->getReturnType()]];
        }

        return $typed;
    }

    // PHPStan types a promoted parameter like its property, so a parameter the constructor promotes is judged by its @param in promotedInputFindings() instead
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

    // a method a trait or a parent brings has no line in this class, so it is reported where the class starts
    private function lineOf(string $method, ClassLike $declaration): int
    {
        $declared = $declaration->getMethod($method);

        return $declared instanceof ClassMethod ? $declared->getStartLine() : $declaration->getStartLine();
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

    // a nullable or union type crosses the boundary in each of its members, and a conditional type in each of its branches, so each is judged on its own
    private function isRefused(Type $type): bool
    {
        if ($type instanceof UnionType) {
            return array_any($type->getTypes(), $this->isRefused(...));
        }

        if ($type instanceof ConditionalType || $type instanceof ConditionalTypeForParameter) {
            return $this->isRefused($type->getIf()) || $this->isRefused($type->getElse());
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

    // callers of a public constructor pass a promoted parameter in its @param shape, whatever the property keeps (@var) and whatever its visibility, so the property's type neither adds nor clears a finding; a private constructor is reached only through the class's own typed factories
    /**
     * @param list<string> $promoted
     *
     * @return list<IdentifierRuleError>
     */
    private function promotedInputFindings(ClassReflection $class, ClassLike $declaration, array $promoted): array
    {
        $constructor = $class->getNativeReflection()->getConstructor();

        if (!$constructor instanceof ReflectionMethod || !$constructor->isPublic() || !$this->isJudgedHere($constructor->getDeclaringClass(), $class)) {
            return [];
        }

        $tags = $this->paramTagsOf($constructor);
        $typed = $this->parameterTypesByName($class, $constructor->getName());
        $findings = [];

        foreach ($this->promotedParametersOf($constructor) as $name) {
            $input = $tags[$name] ?? $typed[$name];
            $reportedAsProperty = in_array($name, $promoted, true) && $input->equals($class->getNativeProperty($name)->getReadableType());

            if (!$reportedAsProperty && $this->isRefused($input)) {
                $findings[] = $this->finding(sprintf('Method %s::%s() parameter $%s is typed %s', $class->getDisplayName(), $constructor->getName(), $name, $this->described($input)), $this->lineOf($constructor->getName(), $declaration));
            }
        }

        return $findings;
    }

    // the PHPDoc is resolved in the file and under the class or trait that declares it, by their declared names, since PHP accepts both in any case; through the using class's file PHPStan finds a trait's PHPDoc only when the `use` line spells the trait's name exactly as declared
    /**
     * @return array<string, Type>
     */
    private function paramTagsOf(ReflectionMethod $constructor): array
    {
        $declaring = $constructor->getBetterReflection()->getDeclaringClass();

        if (!is_string($comment = $constructor->getDocComment()) || !is_string($file = $declaring->getFileName())) {
            return [];
        }

        $tags = $this->typeMapper->getResolvedPhpDoc($file, $declaring->getName(), null, $constructor->getName(), $comment)->getParamTags();

        return array_map(static fn (ParamTag $tag): Type => $tag->getType(), $tags);
    }

    // the fallback for a parameter without its own @param, because PHPStan then types a promoted parameter like its property
    /**
     * @return array<string, Type>
     */
    private function parameterTypesByName(ClassReflection $class, string $method): array
    {
        $typed = [];

        foreach ($class->getNativeMethod($method)->getVariants() as $variant) {
            foreach ($variant->getParameters() as $parameter) {
                $typed[$parameter->getName()] = $parameter->getType();
            }
        }

        return $typed;
    }

    /**
     * @param list<string> $promoted
     *
     * @return list<IdentifierRuleError>
     */
    private function propertyFindings(array $promoted, ClassReflection $class, ClassLike $declaration): array
    {
        $lines = $this->promotedLinesOf($declaration);
        $findings = [];

        foreach ($promoted as $name) {
            $type = $class->getNativeProperty($name)->getReadableType();

            if ($this->isRefused($type)) {
                $findings[] = $this->finding(sprintf('Property %s::$%s is typed %s', $class->getDisplayName(), $name, $this->described($type)), $lines[$name] ?? $declaration->getStartLine());
            }
        }

        return $findings;
    }

    // a property a trait or a parent promotes has no line in this class, so it is reported where the class starts
    /**
     * @return array<string, int>
     */
    private function promotedLinesOf(ClassLike $declaration): array
    {
        $constructor = $declaration->getMethod('__construct');
        $lines = [];

        foreach ($constructor instanceof ClassMethod ? $constructor->params : [] as $param) {
            if ($param->isPromoted() && $param->var instanceof Variable && is_string($param->var->name)) {
                $lines[$param->var->name] = $param->getStartLine();
            }
        }

        return $lines;
    }
}
