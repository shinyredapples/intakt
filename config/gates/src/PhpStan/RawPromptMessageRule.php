<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Core\Review\Contract\PromptAssembly;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

// Model messages are built only by the assembly class, so Intakt's own text arrives as literals and untrusted text only fenced: docs/design/05-architecture/14-threat-model.md#third-party-text
/**
 * @implements Rule<Node>
 */
final readonly class RawPromptMessageRule implements Rule
{
    public const string ASSEMBLY = PromptAssembly::class;
    private const array JUDGED_FOLDERS = ['/src/', '/bin/', '/config/'];
    private const string MESSAGE = Message::class;
    private const array MESSAGE_FACTORIES = ['ofuser', 'forsystem', 'ofassistant'];
    private const array MESSAGE_CLASSES = [UserMessage::class, SystemMessage::class];
    private const string ADVICE = ' builds a model message outside PromptAssembly: build it through '.self::ASSEMBLY.', with Intakt\'s own text as a literal and untrusted text as a FencedText from Fence.';

    private ClassNames $classNames;

    private RolePromptCarriers $carriers;

    public function __construct(private string $root)
    {
        $this->classNames = new ClassNames();
        $this->carriers = new RolePromptCarriers($this->classNames);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isJudged($scope)) {
            return [];
        }

        if ($node instanceof Trait_) {
            return $this->carriers->traitFindings($node);
        }

        $finding = match (true) {
            $node instanceof New_ => $this->constructionFinding($node, $scope),
            // because the assembly may build messages, but not the objects that carry a role prompt
            $this->isInClass($scope, self::ASSEMBLY) => null,
            $node instanceof StaticCall => $this->factoryFinding($node, $scope),
            $node instanceof Array_ => $this->callableArrayFinding($node, $scope),
            $node instanceof String_ => $this->callableStringFinding($node),
            default => null,
        };

        return $finding instanceof IdentifierRuleError ? [$finding] : [];
    }

    public function isJudgedFile(string $file): bool
    {
        return array_any(self::JUDGED_FOLDERS, fn (string $folder): bool => str_starts_with($file, $this->root.$folder));
    }

    private function isJudged(Scope $scope): bool
    {
        // because a trait's own file decides, not the file of the class that uses it
        return $this->isJudgedFile($scope->getTraitReflection()?->getFileName() ?? $scope->getFile());
    }

    private function constructionFinding(New_ $construction, Scope $scope): ?IdentifierRuleError
    {
        $messageClass = $this->classNames->namedAmong($construction->class, self::MESSAGE_CLASSES, $scope)[0] ?? null;

        if (null !== $messageClass && !$this->isInClass($scope, self::ASSEMBLY)) {
            return $this->rawMessage('new '.substr($messageClass, (int) strrpos($messageClass, '\\') + 1).'()');
        }

        return $this->carriers->constructionFinding($construction, $scope);
    }

    private function isInClass(Scope $scope, string $class): bool
    {
        return 0 === strcasecmp($scope->getClassReflection()?->getName() ?? '', $class);
    }

    private function factoryFinding(StaticCall $call, Scope $scope): ?IdentifierRuleError
    {
        $factory = array_find($this->methodNamesOf($call, $scope), static fn (string $name): bool => in_array(strtolower($name), self::MESSAGE_FACTORIES, true));

        if (null === $factory || [] === $this->classNames->namedAmong($call->class, [self::MESSAGE], $scope)) {
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

        if (2 !== count($keys) || false === $class || false === $method || !$this->namesMessage($this->classNames->ofType($array->getValueTypes()[$class]))) {
            return null;
        }

        return $this->factoryNamed($array->getValueTypes()[$method]);
    }

    /**
     * @param list<string> $names
     */
    private function namesMessage(array $names): bool
    {
        return [] !== $this->classNames->matching($names, [self::MESSAGE]);
    }

    // a method name known only at run time may be a factory, so any type that may be a string counts as one, `?string` too
    private function factoryNamed(Type $method): ?string
    {
        $names = array_map(static fn (ConstantStringType $name): string => $name->getValue(), $method->getConstantStrings());

        return array_find($names, static fn (string $name): bool => in_array(strtolower($name), self::MESSAGE_FACTORIES, true))
            ?? ([] === $names && !$method->isString()->no() ? 'a factory name' : null);
    }

    private function callableStringFinding(String_ $string): ?IdentifierRuleError
    {
        $parts = explode('::', $this->classNames->withoutLeadingBackslash($string->value));

        if (2 !== count($parts) || !$this->namesMessage([$parts[0]]) || !in_array(strtolower($parts[1]), self::MESSAGE_FACTORIES, true)) {
            return null;
        }

        return $this->rawMessage('Message::'.$parts[1].'()');
    }

    private function rawMessage(string $construct): IdentifierRuleError
    {
        return RuleErrorBuilder::message($construct.self::ADVICE)->identifier('intakt.rawPromptMessage')->build();
    }
}
