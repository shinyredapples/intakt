<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

// Model messages are built only by the assembly class, so Intakt's own text arrives as literals and untrusted text only fenced: docs/design/05-architecture/14-threat-model.md#third-party-text
/**
 * @implements Rule<Expr>
 */
final readonly class RawPromptMessageRule implements Rule
{
    public const string ASSEMBLY = PromptAssembly::class;
    private const array JUDGED_FOLDERS = ['/src/', '/bin/', '/config/'];
    private const string MESSAGE = Message::class;
    private const array MESSAGE_FACTORIES = ['ofuser', 'forsystem', 'ofassistant'];
    private const array MESSAGE_CLASSES = [UserMessage::class, SystemMessage::class];
    private const string CONVERSATION = ReviewerConversation::class;
    private const string ROLE_HANDLER = ReviewWithReviewerHandler::class;
    private const string ROUND_REVIEWER = RoundReviewer::class;
    private const string ADVICE = ' builds a model message outside PromptAssembly: build it through '.self::ASSEMBLY.', with Intakt\'s own text as a literal and untrusted text as a FencedText from Fence.';
    private const string ROLE_ADVICE = 'new ReviewerConversation() in ReviewWithReviewerHandler takes a role prompt that is not $reviewer->rolePrompt() of a RoundReviewer: pass the round reviewer\'s rolePrompt() as the role prompt, so only the panel\'s configured prompt reaches PromptAssembly::systemWithRole().';

    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isJudged($scope)) {
            return [];
        }

        $finding = match (true) {
            $node instanceof StaticCall => $this->factoryFinding($node, $scope),
            $node instanceof New_ => $this->constructionFinding($node, $scope),
            $node instanceof Array_ => $this->callableArrayFinding($node, $scope),
            $node instanceof String_ => $this->callableStringFinding($node),
            default => null,
        };

        return $finding instanceof IdentifierRuleError ? [$finding] : [];
    }

    private function isJudged(Scope $scope): bool
    {
        // because a trait's own file decides, not the file of the class that uses it
        $file = $scope->getTraitReflection()?->getFileName() ?? $scope->getFile();

        return array_any(self::JUDGED_FOLDERS, fn (string $folder): bool => str_starts_with($file, $this->root.$folder))
            && !$this->isInClass($scope, self::ASSEMBLY);
    }

    private function factoryFinding(StaticCall $call, Scope $scope): ?IdentifierRuleError
    {
        $factory = array_find($this->methodNamesOf($call, $scope), static fn (string $name): bool => in_array(strtolower($name), self::MESSAGE_FACTORIES, true));

        if (null === $factory || null === $this->namedClass($call->class, [self::MESSAGE], $scope)) {
            return null;
        }

        return $this->rawMessage('Message::'.$factory.'()');
    }

    // because `Message::{'ofUser'}()` and `Message::$method()` name the factory through a string PHPStan may know
    /**
     * @return list<string>
     */
    private function methodNamesOf(StaticCall $call, Scope $scope): array
    {
        return $call->name instanceof Identifier
            ? [$call->name->toString()]
            : array_map(static fn (ConstantStringType $name): string => $name->getValue(), $scope->getType($call->name)->getConstantStrings());
    }

    private function constructionFinding(New_ $construction, Scope $scope): ?IdentifierRuleError
    {
        $messageClass = $this->namedClass($construction->class, self::MESSAGE_CLASSES, $scope);

        if (null !== $messageClass) {
            return $this->rawMessage('new '.substr($messageClass, (int) strrpos($messageClass, '\\') + 1).'()');
        }

        if ($this->isInClass($scope, self::ROLE_HANDLER) && null !== $this->namedClass($construction->class, [self::CONVERSATION], $scope) && !$this->rolePromptIsProven($construction, $scope)) {
            return RuleErrorBuilder::message(self::ROLE_ADVICE)->identifier('intakt.unprovenRolePrompt')->build();
        }

        return null;
    }

    // because `[Message::class, 'ofUser']` is a callable only with exactly the keys 0 and 1, in any written order
    private function callableArrayFinding(Array_ $array, Scope $scope): ?IdentifierRuleError
    {
        $factories = array_filter(array_map($this->factoryOfCallableArray(...), $scope->getType($array)->getConstantArrays()), static fn (?string $factory): bool => null !== $factory);

        return [] === $factories ? null : $this->rawMessage('A callable array of Message and '.array_first($factories));
    }

    private function factoryOfCallableArray(ConstantArrayType $array): ?string
    {
        $keys = array_map(static fn (Type $key): ?int => $key instanceof ConstantIntegerType ? $key->getValue() : null, $array->getKeyTypes());
        $class = array_search(0, $keys, true);
        $method = array_search(1, $keys, true);

        if (2 !== count($keys) || false === $class || false === $method || !$this->namesMessage($this->classNamesOf($array->getValueTypes()[$class]))) {
            return null;
        }

        return $this->factoryNamed($array->getValueTypes()[$method]);
    }

    private function callableStringFinding(String_ $string): ?IdentifierRuleError
    {
        $parts = explode('::', $this->withoutLeadingBackslash($string->value));

        if (2 !== count($parts) || !$this->namesMessage([$parts[0]]) || !in_array(strtolower($parts[1]), self::MESSAGE_FACTORIES, true)) {
            return null;
        }

        return $this->rawMessage('Message::'.$parts[1].'()');
    }

    /**
     * @param list<string> $candidates
     */
    private function namedClass(Name|Expr|Class_ $class, array $candidates, Scope $scope): ?string
    {
        $names = match (true) {
            $class instanceof Name => [$scope->resolveName($class)],
            $class instanceof Expr => $this->classNamesOf($scope->getType($class)),
            default => [],
        };

        return array_find($candidates, static fn (string $candidate): bool => array_any($names, static fn (string $name): bool => 0 === strcasecmp($name, $candidate)));
    }

    // because `$message::ofUser()` names the class through an object and `new $class()` through a class-name string, constant or typed `class-string<...>`
    /**
     * @return list<string>
     */
    private function classNamesOf(Type $type): array
    {
        return [
            ...$type->getObjectTypeOrClassStringObjectType()->getObjectClassNames(),
            ...array_map(fn (ConstantStringType $name): string => $this->withoutLeadingBackslash($name->getValue()), $type->getConstantStrings()),
        ];
    }

    /**
     * @param list<string> $names
     */
    private function namesMessage(array $names): bool
    {
        return array_any($names, static fn (string $name): bool => 0 === strcasecmp($name, self::MESSAGE));
    }

    // a method name known only at run time may be a factory, so any type that may be a string counts as one, `?string` too
    private function factoryNamed(Type $method): ?string
    {
        $names = array_map(static fn (ConstantStringType $name): string => $name->getValue(), $method->getConstantStrings());

        return array_find($names, static fn (string $name): bool => in_array(strtolower($name), self::MESSAGE_FACTORIES, true))
            ?? ([] === $names && !$method->isString()->no() ? 'a factory name' : null);
    }

    // because PHP accepts at most one leading backslash in a class string
    private function withoutLeadingBackslash(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }

    private function rolePromptIsProven(New_ $construction, Scope $scope): bool
    {
        $rolePrompt = $this->rolePromptArgument($construction);

        return $rolePrompt instanceof Arg && $this->readsTheRoundReviewersRolePrompt($rolePrompt->value, $scope);
    }

    // an unpacked argument names no role prompt, so any unpack in the call, also after the role prompt, proves none
    private function rolePromptArgument(New_ $construction): ?Arg
    {
        $rolePrompt = null;
        foreach ($construction->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if ('rolePrompt' === $argument->name?->toString() || (!$argument->name instanceof Identifier && 0 === $position)) {
                $rolePrompt = $argument;
            }
        }

        return $rolePrompt;
    }

    private function readsTheRoundReviewersRolePrompt(Expr $value, Scope $scope): bool
    {
        return $value instanceof MethodCall
            && $value->name instanceof Identifier
            && 'roleprompt' === $value->name->toLowerString()
            && new ObjectType(self::ROUND_REVIEWER)->isSuperTypeOf($scope->getType($value->var))->yes();
    }

    private function isInClass(Scope $scope, string $class): bool
    {
        return 0 === strcasecmp($scope->getClassReflection()?->getName() ?? '', $class);
    }

    private function rawMessage(string $construct): IdentifierRuleError
    {
        return RuleErrorBuilder::message($construct.self::ADVICE)->identifier('intakt.rawPromptMessage')->build();
    }
}
