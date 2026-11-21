<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

// because the role prompt enters a system message verbatim, the objects that carry it are built only where it comes from the owner's panel configuration or a fixed literal, a trait body counting as outside: decision 2 of #2812
final readonly class RolePromptCarriers
{
    private const string CONVERSATION = ReviewerConversation::class;
    private const string ROUND_REVIEWER = RoundReviewer::class;
    private const string RETRY = SymfonyAiVendor::class;
    private const string ROLE_ADVICE = 'new ReviewerConversation() in ReviewWithReviewerHandler takes a role prompt that is not $reviewer->rolePrompt() of a RoundReviewer: pass the round reviewer\'s rolePrompt() as the role prompt, so only the panel\'s configured prompt reaches PromptAssembly::systemWithRole().';
    private const string RETRY_ADVICE = 'new ReviewerConversation() in SymfonyAiVendor takes a role prompt that is not the rolePrompt of an existing ReviewerConversation: copy the stopped conversation\'s rolePrompt, so only the panel\'s configured prompt reaches PromptAssembly::systemWithRole().';
    private const string TRAIT_ADVICE = 'A trait builds an object whose class is known only in the class that uses it: a trait body is outside ConfiguredPanels, SampleProject, ReviewRound, ReviewWithReviewerHandler and SymfonyAiVendor, so it may not build a ReviewerRole, RoundReviewer or ReviewerConversation; name the class it builds, or build the object in a class.';
    private const array BUILDERS = [
        ReviewerRole::class => [ConfiguredPanels::class, SampleProject::class],
        RoundReviewer::class => [ReviewRound::class],
        ReviewerConversation::class => [ReviewWithReviewerHandler::class, SymfonyAiVendor::class],
    ];
    private const array BUILDER_FINDINGS = [
        ReviewerRole::class => ['intakt.reviewerRole', 'new ReviewerRole() outside ConfiguredPanels and SampleProject: a role prompt enters the system message verbatim, so build a ReviewerRole only from the owner\'s panel configuration (ConfiguredPanels) or as a fixed sample role (SampleProject).'],
        RoundReviewer::class => ['intakt.reviewerRole', 'new RoundReviewer() outside ReviewRound: a round reviewer carries its panel member\'s role prompt, so let ReviewRound build it from a PanelReviewer of the round\'s panel.'],
        ReviewerConversation::class => ['intakt.reviewerConversation', 'new ReviewerConversation() outside ReviewWithReviewerHandler and SymfonyAiVendor: a conversation carries the role prompt PromptAssembly::systemWithRole() puts into the system message, so start it in ReviewWithReviewerHandler from $reviewer->rolePrompt(), or copy an existing conversation\'s rolePrompt in SymfonyAiVendor\'s retry.'],
    ];

    public function __construct(private ClassNames $classNames)
    {
    }

    public function constructionFinding(New_ $construction, Scope $scope): ?IdentifierRuleError
    {
        // because traitFindings() judges every construction in a trait where the trait is declared, also in a trait no analyzed class uses
        if ($scope->isInTrait()) {
            return null;
        }

        // because a class-string union may name an allowed carrier beside a refused one, every carrier it names is judged
        $carriers = $this->classNames->namedAmong($construction->class, array_keys(self::BUILDERS), $scope);
        $misplaced = array_find($carriers, fn (string $carrier): bool => !array_any(self::BUILDERS[$carrier], fn (string $builder): bool => $this->isInClass($scope, $builder)));

        if (null !== $misplaced) {
            return $this->misplaced($misplaced)->build();
        }

        if (in_array(self::CONVERSATION, $carriers, true) && !$this->rolePromptIsProven($construction, $scope)) {
            return RuleErrorBuilder::message($this->isInClass($scope, self::RETRY) ? self::RETRY_ADVICE : self::ROLE_ADVICE)->identifier('intakt.unprovenRolePrompt')->build();
        }

        return null;
    }

    // PHPStan analyzes a trait's body only inside a class that uses it, so the trait is judged where it is declared, without the using class's types: a trait body is outside every builder, and a class it names only at run time may be a carrier
    /**
     * @return list<IdentifierRuleError>
     */
    public function traitFindings(Trait_ $trait, Scope $scope): array
    {
        $findings = [];
        foreach (new NodeFinder()->findInstanceOf($trait->stmts, New_::class) as $construction) {
            $names = $this->traitClassNamesOf($construction->class, $scope);
            $carrier = null === $names ? null : $this->classNames->matching($names, array_keys(self::BUILDERS))[0] ?? null;
            $finding = null === $names ? RuleErrorBuilder::message(self::TRAIT_ADVICE)->identifier('intakt.reviewerRole') : (null === $carrier ? null : $this->misplaced($carrier));

            if (null !== $finding) {
                $findings[] = $finding->line($construction->getStartLine())->build();
            }
        }

        return $findings;
    }

    // null when the class is known only at run time: `self`, `static` and `parent` name the using class, and a variable's type is known only inside it
    /**
     * @return list<string>|null
     */
    private function traitClassNamesOf(Name|Expr|Class_ $class, Scope $scope): ?array
    {
        $names = match (true) {
            $class instanceof Class_ => [],
            $class instanceof Name => $class->isSpecialClassName() ? null : [$class->toString()],
            default => $this->classNames->ofType($scope->getType($class)),
        };

        return [] === $names && $class instanceof Expr ? null : $names;
    }

    /**
     * @return RuleErrorBuilder<IdentifierRuleError>
     */
    private function misplaced(string $carrier): RuleErrorBuilder
    {
        [$identifier, $advice] = self::BUILDER_FINDINGS[$carrier];

        return RuleErrorBuilder::message($advice)->identifier($identifier);
    }

    private function rolePromptIsProven(New_ $construction, Scope $scope): bool
    {
        $rolePrompt = $this->rolePromptArgument($construction);

        if (!$rolePrompt instanceof Arg) {
            return false;
        }

        return $this->isInClass($scope, self::RETRY)
            ? $this->copiesAConversationsRolePrompt($rolePrompt->value, $scope)
            : $this->readsTheRoundReviewersRolePrompt($rolePrompt->value, $scope);
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

    // because a retry starts a new conversation with the stopped one's role prompt, which the handler proved
    private function copiesAConversationsRolePrompt(Expr $value, Scope $scope): bool
    {
        return $value instanceof PropertyFetch
            && $value->name instanceof Identifier
            && 'rolePrompt' === $value->name->toString()
            && new ObjectType(self::CONVERSATION)->isSuperTypeOf($scope->getType($value->var))->yes();
    }

    private function isInClass(Scope $scope, string $class): bool
    {
        return 0 === strcasecmp($scope->getClassReflection()?->getName() ?? '', $class);
    }
}
