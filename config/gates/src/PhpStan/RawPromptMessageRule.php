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
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
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
            // because PHPStan hands a first-class callable `Message::ofUser(...)` over as its own node
            $node instanceof StaticMethodCallableNode => $this->factoryFinding($node->getOriginalNode(), $scope),
            $node instanceof New_ => $this->constructionFinding($node, $scope),
            default => null,
        };

        return $finding instanceof IdentifierRuleError ? [$finding] : [];
    }

    private function isJudged(Scope $scope): bool
    {
        $file = $scope->getFile();

        return array_any(self::JUDGED_FOLDERS, fn (string $folder): bool => str_starts_with($file, $this->root.$folder))
            && !$this->isInClass($scope, self::ASSEMBLY);
    }

    private function factoryFinding(StaticCall $call, Scope $scope): ?IdentifierRuleError
    {
        if (!$call->name instanceof Identifier || !in_array($call->name->toLowerString(), self::MESSAGE_FACTORIES, true) || null === $this->namedClass($call->class, [self::MESSAGE], $scope)) {
            return null;
        }

        return $this->rawMessage('Message::'.$call->name->toString().'()');
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

        return array_find($candidates, static fn (string $candidate): bool => array_any($names, static fn (string $name): bool => 0 === strcasecmp(ltrim($name, '\\'), $candidate)));
    }

    // because `$message::ofUser()` names the class through an object and `new $class()` through a class-name string
    /**
     * @return list<string>
     */
    private function classNamesOf(Type $type): array
    {
        return [...$type->getObjectClassNames(), ...array_map(static fn (ConstantStringType $name): string => $name->getValue(), $type->getConstantStrings())];
    }

    private function rolePromptIsProven(New_ $construction, Scope $scope): bool
    {
        $rolePrompt = $this->rolePromptArgument($construction);

        return $rolePrompt instanceof Arg && $this->readsTheRoundReviewersRolePrompt($rolePrompt->value, $scope);
    }

    // an unpacked argument names no role prompt, so it never proves one
    private function rolePromptArgument(New_ $construction): ?Arg
    {
        foreach ($construction->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if ('rolePrompt' === $argument->name?->toString() || (!$argument->name instanceof Identifier && 0 === $position)) {
                return $argument;
            }
        }

        return null;
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
