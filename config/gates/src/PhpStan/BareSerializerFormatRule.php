<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
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

    // each method of the Serializer contracts that takes a format, with the interface that declares it and the format's position, because only that argument names a format, so a 'json' string passed as data or a type stays allowed
    private const array FORMAT_ARGUMENTS = [
        'serialize' => [SerializerInterface::class, 1],
        'deserialize' => [SerializerInterface::class, 2],
        'normalize' => [NormalizerInterface::class, 1],
        'supportsnormalization' => [NormalizerInterface::class, 1],
        'denormalize' => [DenormalizerInterface::class, 2],
        'supportsdenormalization' => [DenormalizerInterface::class, 2],
        'encode' => [EncoderInterface::class, 1],
        'supportsencoding' => [EncoderInterface::class, 0],
        'decode' => [DecoderInterface::class, 1],
        'supportsdecoding' => [DecoderInterface::class, 0],
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // a first-class callable has no arguments yet, and asking it for them fails an assertion inside the parser
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall || $node->isFirstClassCallable()) {
            return [];
        }

        $method = $this->methodName($node);

        if (null === $method) {
            return [];
        }

        [$contract, $position] = self::FORMAT_ARGUMENTS[$method] ?? [null, 0];
        $classes = null === $contract ? [] : $this->serializerClasses($scope, $node, $contract);

        if ([] === $classes || !$this->isBareFormat($this->formatArgument($node, $position, $this->formatParameterNames($classes, $method, $position)))) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf("'%s' as a serializer format is a magic string: pass JsonEncoder::FORMAT instead.", self::BARE_FORMAT))
                ->identifier('intakt.bareSerializerFormat')
                ->build(),
        ];
    }

    // a braced literal name such as ->{'serialize'} calls the same method as the plain name
    private function methodName(MethodCall|NullsafeMethodCall|StaticCall $node): ?string
    {
        return match (true) {
            $node->name instanceof Identifier => $node->name->toLowerString(),
            $node->name instanceof String_ => strtolower($node->name->value),
            default => null,
        };
    }

    /**
     * @param list<string> $names
     */
    private function formatArgument(MethodCall|NullsafeMethodCall|StaticCall $call, int $position, array $names): ?Expr
    {
        $index = 0;

        foreach ($this->arguments($call) as [$name, $value]) {
            if (null !== $name && in_array($name, $names, true) || null === $name && $index++ === $position) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Each argument as its name (null when positional) and value, with a literal array unpacked in place, because PHP passes its items as if written out.
     *
     * @return list<array{?string, Expr}>
     */
    private function arguments(MethodCall|NullsafeMethodCall|StaticCall $call): array
    {
        $arguments = [];

        foreach ($call->getArgs() as $argument) {
            if (!$argument->unpack) {
                $arguments[] = [$argument->name?->toString(), $argument->value];

                continue;
            }

            if (!$argument->value instanceof Array_) {
                return $arguments;
            }

            foreach ($argument->value->items as $item) {
                if ($item->unpack || null !== $item->key && !$item->key instanceof String_ && !$item->key instanceof Int_) {
                    return $arguments;
                }

                $arguments[] = [$item->key instanceof String_ ? $item->key->value : null, $item->value];
            }
        }

        return $arguments;
    }

    // only the literal itself, because JsonEncoder::FORMAT has the same constant value and is the spelling the rule asks for
    private function isBareFormat(?Expr $value): bool
    {
        return $value instanceof String_ && self::BARE_FORMAT === $value->value;
    }

    /**
     * Every class the receiver may be that implements the contract, so a nullable or a union receiver still counts while an unrelated class with a method of the same name does not.
     *
     * @return list<ClassReflection>
     */
    private function serializerClasses(Scope $scope, MethodCall|NullsafeMethodCall|StaticCall $call, string $contract): array
    {
        $receiver = match (true) {
            $call instanceof StaticCall && $call->class instanceof Name => $scope->resolveTypeByName($call->class),
            $call instanceof StaticCall => $scope->getType($call->class),
            default => $scope->getType($call->var),
        };

        return array_values(array_filter(
            $receiver->getObjectClassReflections(),
            static fn (ClassReflection $class): bool => $class->getName() === $contract || $class->implementsInterface($contract),
        ));
    }

    /**
     * The name each receiver class gives the format parameter, because an implementation may rename it and a named argument follows the implementation.
     *
     * @param list<ClassReflection> $classes
     *
     * @return list<string>
     */
    private function formatParameterNames(array $classes, string $method, int $position): array
    {
        $names = [];

        foreach ($classes as $class) {
            if (!$class->hasNativeMethod($method)) {
                continue;
            }

            foreach ($class->getNativeMethod($method)->getVariants() as $variant) {
                $parameter = $variant->getParameters()[$position] ?? null;

                if (null !== $parameter) {
                    $names[] = $parameter->getName();
                }
            }
        }

        return [] === $names ? [self::CONTRACT_PARAMETER] : array_values(array_unique($names));
    }
}
