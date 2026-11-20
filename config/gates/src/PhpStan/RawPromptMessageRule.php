<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * @implements Rule<Expr>
 */
final readonly class RawPromptMessageRule implements Rule
{
    private const array FACTORIES = ['ofuser', 'forsystem', 'ofassistant'];

    private const array MESSAGES = [UserMessage::class, SystemMessage::class, AssistantMessage::class];

    // tests build stored conversations as fixtures and never send them to a provider, so every other file (src/, bin/, config/, public/) is judged
    private const string UNJUDGED_FOLDER = '/tests/';

    // one name for the argument and the method, because the decision of 2026-11-19 (#2520) allows only the round reviewer's own rolePrompt() as the source
    private const string ROLE_PROMPT = 'rolePrompt';

    public function __construct(
        private string $root,
        private string $assembly,
        private string $handler,
        private string $conversation,
        private string $roundReviewer,
    ) {
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
        if (!$this->isShipped($scope->getFile())) {
            return [];
        }

        if (($this->buildsAMessage($node, $scope) || $this->namesAFactory($node, $scope)) && !$this->isInside($this->assembly, $scope)) {
            return [$this->rawMessage($node)];
        }

        if ($node instanceof New_ && $this->startsAConversationInTheHandler($node, $scope) && !$this->rolePromptIsProven($node, $scope)) {
            return [$this->unprovenRolePrompt($node)];
        }

        return [];
    }

    private function isShipped(string $file): bool
    {
        return str_starts_with($file, $this->root.'/') && !str_starts_with($file, $this->root.self::UNJUDGED_FOLDER);
    }

    private function buildsAMessage(Expr $node, Scope $scope): bool
    {
        return match (true) {
            $node instanceof StaticCall => $this->names(Message::class, $node->class, $scope) && $this->callsAFactory($node->name, $scope),
            $node instanceof New_ => array_any(self::MESSAGES, fn (string $message): bool => $this->names($message, $node->class, $scope)),
            default => false,
        };
    }

    // a factory handed over as a callable (array_map, call_user_func, Closure::fromCallable) builds the same message as the call
    private function namesAFactory(Expr $node, Scope $scope): bool
    {
        return match (true) {
            $node instanceof Array_ => 2 === count($node->items) && $this->names(Message::class, $node->items[0]->value, $scope) && $this->callsAFactory($node->items[1]->value, $scope),
            $node instanceof String_, $node instanceof Concat, $node instanceof InterpolatedString => array_any($scope->getType($node)->getConstantStrings(), $this->isAFactoryCallable(...)),
            default => false,
        };
    }

    private function isAFactoryCallable(ConstantStringType $callable): bool
    {
        [$class, $method] = explode('::', $callable->getValue(), 2) + ['', ''];

        return strtolower(ltrim($class, '\\')) === strtolower(Message::class) && in_array(strtolower($method), self::FACTORIES, true);
    }

    private function names(string $class, Name|Expr|Class_ $reference, Scope $scope): bool
    {
        $named = match (true) {
            $reference instanceof Name => [$scope->resolveName($reference)],
            $reference instanceof Expr => $scope->getType($reference)->getObjectTypeOrClassStringObjectType()->getObjectClassNames(),
            default => [],
        };

        return in_array(strtolower($class), array_map(strtolower(...), $named), true);
    }

    // a method name only known at runtime could be any factory, so it counts as one
    private function callsAFactory(Identifier|Expr $name, Scope $scope): bool
    {
        if ($name instanceof Identifier) {
            return in_array($name->toLowerString(), self::FACTORIES, true);
        }

        $known = array_map(static fn ($constant): string => strtolower($constant->getValue()), $scope->getType($name)->getConstantStrings());

        return [] === $known || [] !== array_intersect($known, self::FACTORIES);
    }

    private function isInside(string $class, Scope $scope): bool
    {
        return strtolower($scope->getClassReflection()?->getName() ?? '') === strtolower($class);
    }

    private function startsAConversationInTheHandler(New_ $node, Scope $scope): bool
    {
        return $this->names($this->conversation, $node->class, $scope) && $this->isInside($this->handler, $scope);
    }

    // unpacked arguments name no role prompt, so they never prove one
    private function rolePromptIsProven(New_ $node, Scope $scope): bool
    {
        if ($node->isFirstClassCallable() || array_any($node->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
            return false;
        }

        $rolePrompt = $this->rolePromptArgumentOf($node);

        return $rolePrompt instanceof Arg && $this->readsTheRoundReviewersRolePrompt($rolePrompt->value, $scope);
    }

    private function rolePromptArgumentOf(New_ $node): ?Arg
    {
        $first = array_first($node->getArgs());
        $named = array_find($node->getArgs(), static fn (Arg $argument): bool => self::ROLE_PROMPT === $argument->name?->toString());

        return $named ?? ($first?->name instanceof Identifier ? null : $first);
    }

    private function readsTheRoundReviewersRolePrompt(Expr $value, Scope $scope): bool
    {
        return $value instanceof MethodCall
            && $value->name instanceof Identifier
            && strtolower(self::ROLE_PROMPT) === $value->name->toLowerString()
            && new ObjectType($this->roundReviewer)->isSuperTypeOf($scope->getType($value->var))->yes();
    }

    private function rawMessage(Expr $node): IdentifierRuleError
    {
        $message = sprintf('A model message built from a plain string outside %s: build it through PromptAssembly, with Intakt\'s own text as a literal and untrusted text as FencedText (docs/design/05-architecture/14-threat-model.md#third-party-text).', $this->assembly);

        return RuleErrorBuilder::message($message)->identifier('intakt.rawPromptMessage')->line($node->getStartLine())->build();
    }

    private function unprovenRolePrompt(New_ $node): IdentifierRuleError
    {
        $message = sprintf('A ReviewerConversation in %s whose role prompt is not rolePrompt() of a %s: pass $reviewer->rolePrompt() of the round reviewer, without unpacking, because only the owner\'s panel configuration enters the system message verbatim.', $this->handler, $this->roundReviewer);

        return RuleErrorBuilder::message($message)->identifier('intakt.unprovenRolePrompt')->line($node->getStartLine())->build();
    }
}
