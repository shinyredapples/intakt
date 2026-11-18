<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * @implements Rule<Expr>
 */
final readonly class RawPromptMessageRule implements Rule
{
    // only src/, because tests hand fixed messages and conversations to fake platforms, and no outside text reaches a model from there
    private const string CHECKED = '/src/';

    private const string ASSEMBLY = PromptAssembly::class;
    private const string MESSAGE = Message::class;
    private const array MESSAGE_FACTORIES = ['ofuser', 'forsystem', 'ofassistant'];
    private const array MESSAGE_CLASSES = [UserMessage::class, SystemMessage::class];
    private const string MESSAGE_ADVICE = ' builds a model message from a plain string: build it through Intakt\Core\Review\Contract\PromptAssembly, which takes Intakt\'s own text as a literal-string and untrusted text only as a FencedText.';

    // the role prompt enters the system message verbatim (#2518, D2 exception), so a conversation is built only where that prompt comes from the panel member's role or is copied from an existing conversation
    private const string CONVERSATION = ReviewerConversation::class;
    private const array CONVERSATION_SOURCES = [
        ReviewWithReviewerHandler::class,
        SymfonyAiVendor::class,
    ];
    private const string CONVERSATION_ADVICE = 'new ReviewerConversation sets a role prompt that enters the system message verbatim: build one only from the panel member\'s role (ReviewWithReviewerHandler) or by copying an existing conversation\'s role prompt (the retries of SymfonyAiVendor).';

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
        if (!str_starts_with($scope->getFile(), $this->root.self::CHECKED)) {
            return [];
        }

        return match (true) {
            $node instanceof StaticCall => $this->messageFactoryFindings($node, $scope),
            $node instanceof New_ => $this->constructionFindings($node, $scope),
            default => [],
        };
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function messageFactoryFindings(StaticCall $call, Scope $scope): array
    {
        if (!in_array(strtolower(self::MESSAGE), $this->classesNamedBy($call->class, $scope), true) || $this->isInside(self::ASSEMBLY, $scope)) {
            return [];
        }

        // a name computed at run time could be any of the factories, so it counts as one
        if ($call->name instanceof Identifier && !in_array($call->name->toLowerString(), self::MESSAGE_FACTORIES, true)) {
            return [];
        }

        $method = $call->name instanceof Identifier ? $call->name->toString() : '{name}';

        return [RuleErrorBuilder::message('Message::'.$method.'()'.self::MESSAGE_ADVICE)->identifier('intakt.rawPromptMessage')->build()];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function constructionFindings(New_ $construction, Scope $scope): array
    {
        $classes = $this->classesNamedBy($construction->class, $scope);
        $message = array_find(self::MESSAGE_CLASSES, static fn (string $class): bool => in_array(strtolower($class), $classes, true));

        if (null !== $message && !$this->isInside(self::ASSEMBLY, $scope)) {
            return [RuleErrorBuilder::message('new '.substr($message, (int) strrpos($message, '\\') + 1).self::MESSAGE_ADVICE)->identifier('intakt.rawPromptMessage')->build()];
        }

        if (in_array(strtolower(self::CONVERSATION), $classes, true) && !array_any(self::CONVERSATION_SOURCES, fn (string $source): bool => $this->isInside($source, $scope))) {
            return [RuleErrorBuilder::message(self::CONVERSATION_ADVICE)->identifier('intakt.reviewerConversation')->build()];
        }

        return [];
    }

    // through the scope, so an alias or a class name held in an expression still resolves to the class; in lower case, because PHP resolves a class name in any case
    /**
     * @return list<string>
     */
    private function classesNamedBy(Node $class, Scope $scope): array
    {
        $names = match (true) {
            $class instanceof Name => [ltrim($scope->resolveName($class), '\\')],
            $class instanceof Expr => $scope->getType($class)->getObjectTypeOrClassStringObjectType()->getObjectClassNames(),
            default => [],
        };

        return array_map(strtolower(...), $names);
    }

    // a trait body counts as outside, because any class could use it and lend it the exemption
    private function isInside(string $class, Scope $scope): bool
    {
        return !$scope->getTraitReflection() instanceof ClassReflection && 0 === strcasecmp($scope->getClassReflection()?->getName() ?? '', $class);
    }
}
