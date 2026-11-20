<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
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
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall || !$node->name instanceof Identifier) {
            return [];
        }

        [$contract, $position] = self::FORMAT_ARGUMENTS[$node->name->toLowerString()] ?? [null, 0];

        if (null === $contract || !$this->isBareFormat($this->formatArgument($node, $position)) || !$this->callsInto($scope, $node, $contract)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf("'%s' as a serializer format is a magic string: pass JsonEncoder::FORMAT instead.", self::BARE_FORMAT))
                ->identifier('intakt.bareSerializerFormat')
                ->build(),
        ];
    }

    private function formatArgument(MethodCall|NullsafeMethodCall $call, int $position): ?Arg
    {
        foreach ($call->getArgs() as $index => $argument) {
            $named = $argument->name?->toString();

            if ('format' === $named || null === $named && $index === $position) {
                return $argument;
            }
        }

        return null;
    }

    // only the literal itself, because JsonEncoder::FORMAT has the same constant value and is the spelling the rule asks for
    private function isBareFormat(?Arg $argument): bool
    {
        return $argument?->value instanceof String_ && self::BARE_FORMAT === $argument->value->value;
    }

    // every class the receiver may be, so a nullable or a union receiver still counts while an unrelated class with a method of the same name does not
    private function callsInto(Scope $scope, MethodCall|NullsafeMethodCall $call, string $contract): bool
    {
        $classes = $scope->getType($call->var)->getObjectClassReflections();

        return [] !== array_filter(
            $classes,
            static fn (ClassReflection $class): bool => $class->getName() === $contract || $class->implementsInterface($contract),
        );
    }
}
