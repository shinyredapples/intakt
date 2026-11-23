<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @implements Rule<CallLike>
 */
final readonly class BareSerializerFormatRule implements Rule
{
    private const string BARE_FORMAT = 'json';

    private const string CONTRACT_PARAMETER = 'format';

    // each method of the Serializer contracts that takes a format, with the interfaces that declare it (both normalizer contracts declare getSupportedTypes()) and the format's position, because only that argument names a format, so a 'json' string passed as data or a type stays allowed
    private const array FORMAT_ARGUMENTS = [
        'serialize' => [[SerializerInterface::class], 1],
        'deserialize' => [[SerializerInterface::class], 2],
        'normalize' => [[NormalizerInterface::class], 1],
        'supportsnormalization' => [[NormalizerInterface::class], 1],
        'denormalize' => [[DenormalizerInterface::class], 2],
        'supportsdenormalization' => [[DenormalizerInterface::class], 2],
        'getsupportedtypes' => [[NormalizerInterface::class, DenormalizerInterface::class], 0],
        'encode' => [[EncoderInterface::class], 1],
        'supportsencoding' => [[EncoderInterface::class], 0],
        'decode' => [[DecoderInterface::class], 1],
        'supportsdecoding' => [[DecoderInterface::class], 0],
    ];

    public function __construct(
        private SerializerCallArguments $arguments = new SerializerCallArguments(),
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // checked first because a first-class callable has no arguments, and asking it for them fails an assertion inside the parser; a nullsafe call is left out because PHPStan also hands the rule the same call as a plain MethodCall, so handling both reported it twice
        if (!$node instanceof MethodCall && !$node instanceof StaticCall || $node->isFirstClassCallable()) {
            return [];
        }

        $method = $this->methodName($node);
        [$contracts, $position] = self::FORMAT_ARGUMENTS[$method] ?? [[], 0];
        $classes = [] === $contracts ? [] : $this->serializerClasses($scope, $node, $contracts);

        // judged against each receiver's own signature, because names merged across a union let a named argument that is the format on one receiver hide the format on another
        $passesBareFormat = array_any(
            $classes,
            fn (ClassReflection $class): bool => $this->isBareFormat($this->arguments->valueAt($node, $position, $this->formatParameterNames($class, $method, $position))),
        );

        if (!$passesBareFormat) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf("'%s' as a serializer format is a magic string: pass JsonEncoder::FORMAT instead.", self::BARE_FORMAT))
                ->identifier('intakt.bareSerializerFormat')
                ->build(),
        ];
    }

    // a braced literal name such as ->{'serialize'} counts too, because it calls the same method as the plain name
    private function methodName(MethodCall|StaticCall $call): string
    {
        return match (true) {
            $call->name instanceof Identifier => $call->name->toLowerString(),
            $call->name instanceof String_ => strtolower($call->name->value),
            default => '',
        };
    }

    // every class the receiver may be, so a nullable or a union receiver still counts while an unrelated class with a method of the same name does not; null is removed first, because PHPStan gives no class for a union that holds null, as the receiver $holder?->serializer has
    /**
     * @param list<class-string> $contracts
     *
     * @return list<ClassReflection>
     */
    private function serializerClasses(Scope $scope, MethodCall|StaticCall $call, array $contracts): array
    {
        $receiver = match (true) {
            $call instanceof StaticCall && $call->class instanceof Name => $scope->resolveTypeByName($call->class),
            $call instanceof StaticCall => $scope->getType($call->class),
            default => $scope->getType($call->var),
        };

        return array_values(array_filter(
            TypeCombinator::removeNull($receiver)->getObjectClassReflections(),
            static fn (ClassReflection $class): bool => array_any(
                $contracts,
                static fn (string $contract): bool => $class->getName() === $contract || $class->implementsInterface($contract),
            ),
        ));
    }

    // read from the receiver class, because an implementation may rename the format parameter and a named argument follows the implementation
    /**
     * @return list<string>
     */
    private function formatParameterNames(ClassReflection $class, string $method, int $position): array
    {
        $names = $this->parameterNamesOf($class, $method, $position);

        return [] === $names ? [self::CONTRACT_PARAMETER] : array_values(array_unique($names));
    }

    // only the literal itself, because JsonEncoder::FORMAT has the same constant value and is the spelling the rule asks for
    private function isBareFormat(?Expr $value): bool
    {
        return $value instanceof String_ && self::BARE_FORMAT === $value->value;
    }

    /**
     * @return list<string>
     */
    private function parameterNamesOf(ClassReflection $class, string $method, int $position): array
    {
        if (!$class->hasNativeMethod($method)) {
            return [];
        }

        $parameters = array_map(
            static fn (ParametersAcceptor $variant): ?ParameterReflection => $variant->getParameters()[$position] ?? null,
            $class->getNativeMethod($method)->getVariants(),
        );

        return array_values(array_map(static fn (ParameterReflection $parameter): string => $parameter->getName(), array_filter($parameters)));
    }
}
