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
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
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
    private const string TRAIT_ADVICE = 'A trait no class uses builds an object whose class is known only in a class that uses it: a trait body is outside ConfiguredPanels, SampleProject, ReviewRound, ReviewWithReviewerHandler and SymfonyAiVendor, so it may not build a ReviewerRole, RoundReviewer or ReviewerConversation; name the class it builds, or build the object in a class.';
    private const string UNNAMED_ADVICE = 'A trait builds an object whose class PHPStan cannot name, so it may be a ReviewerRole, RoundReviewer or ReviewerConversation, and a trait body is outside ConfiguredPanels, SampleProject, ReviewRound, ReviewWithReviewerHandler and SymfonyAiVendor; type the class string with the classes it may name (a class-string<...> or X::class), or build the object in a class.';
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
            return $this->usedTraitFinding($construction, $scope);
        }

        // because a class-string union may name an allowed carrier beside a refused one, every carrier it names is judged
        $carriers = $this->classNames->standingFor($construction->class, array_keys(self::BUILDERS), $scope);
        $misplaced = array_find($carriers, fn (string $carrier): bool => !array_any(self::BUILDERS[$carrier], fn (string $builder): bool => $this->isInClass($scope, $builder)));

        if (null !== $misplaced) {
            return $this->misplaced($misplaced)->build();
        }

        if (in_array(self::CONVERSATION, $carriers, true) && !$this->rolePromptIsProven($construction, $scope)) {
            return RuleErrorBuilder::message($this->isInClass($scope, self::RETRY) ? self::RETRY_ADVICE : self::ROLE_ADVICE)->identifier('intakt.unprovenRolePrompt')->build();
        }

        return null;
    }

    // PHPStan analyzes a trait's body only inside a class that uses it, so a class the trait names literally is judged where the trait is declared: a trait body is outside every builder
    /**
     * @return list<IdentifierRuleError>
     */
    public function traitFindings(Trait_ $trait): array
    {
        $findings = array_map($this->namedTraitConstructionFinding(...), $this->constructionsIn($trait));

        return array_values(array_filter($findings, static fn (?IdentifierRuleError $finding): bool => $finding instanceof IdentifierRuleError));
    }

    /**
     * @return list<int>
     */
    public function runTimeConstructionLines(Trait_ $trait): array
    {
        $buildsItsAnonymousClass = $this->anonymousClassSelfConstructions($trait);
        $runTime = array_filter($this->constructionsIn($trait), fn (New_ $construction): bool => null === $this->traitClassNamesOf($construction->class) && !isset($buildsItsAnonymousClass[spl_object_id($construction)]));

        return array_values(array_map(static fn (New_ $construction): int => $construction->getStartLine(), $runTime));
    }

    public function unusedTraitFinding(string $file, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::TRAIT_ADVICE)->identifier('intakt.reviewerRole')->file($file)->line($line)->build();
    }

    // because inside a using class the trait's method has its own types: a class expression PHPStan names fully is judged by those names, one it cannot name may be a carrier; a literal name is left to traitFindings(), which already names it
    private function usedTraitFinding(New_ $construction, Scope $scope): ?IdentifierRuleError
    {
        if (null !== $this->traitClassNamesOf($construction->class)) {
            return null;
        }

        $names = $this->classNames->fullyNamed($construction->class, $scope);

        if (null === $names) {
            return RuleErrorBuilder::message(self::UNNAMED_ADVICE)->identifier('intakt.reviewerRole')->build();
        }

        $carrier = $this->classNames->standingFor($construction->class, array_keys(self::BUILDERS), $scope)[0] ?? null;

        return null === $carrier ? null : $this->misplaced($carrier)->build();
    }

    private function isInClass(Scope $scope, string $class): bool
    {
        return 0 === strcasecmp($scope->getClassReflection()?->getName() ?? '', $class);
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

    // because a retry starts a new conversation with the stopped one's role prompt, which the handler proved
    private function copiesAConversationsRolePrompt(Expr $value, Scope $scope): bool
    {
        return $value instanceof PropertyFetch
            && $value->name instanceof Identifier
            && 'rolePrompt' === $value->name->toString()
            && new ObjectType(self::CONVERSATION)->isSuperTypeOf($scope->getType($value->var))->yes();
    }

    private function readsTheRoundReviewersRolePrompt(Expr $value, Scope $scope): bool
    {
        return $value instanceof MethodCall
            && $value->name instanceof Identifier
            && 'roleprompt' === $value->name->toLowerString()
            && new ObjectType(self::ROUND_REVIEWER)->isSuperTypeOf($scope->getType($value->var))->yes();
    }

    private function namedTraitConstructionFinding(New_ $construction): ?IdentifierRuleError
    {
        $carrier = $this->classNames->matching($this->traitClassNamesOf($construction->class) ?? [], array_keys(self::BUILDERS))[0] ?? null;

        return null === $carrier ? null : $this->misplaced($carrier)->line($construction->getStartLine())->build();
    }

    /**
     * @return list<New_>
     */
    private function constructionsIn(Trait_ $trait): array
    {
        return array_values(new NodeFinder()->findInstanceOf($trait->stmts, New_::class));
    }

    // because inside an anonymous class `self`, `static` and `parent` name that class or the parent it names literally, never the class that uses the trait, and a carrier is final, so neither is one
    /**
     * @return array<int, true>
     */
    private function anonymousClassSelfConstructions(Trait_ $trait): array
    {
        $finder = new NodeFinder();
        $ids = [];

        foreach ($finder->findInstanceOf($trait->stmts, Class_::class) as $anonymous) {
            foreach ($finder->findInstanceOf($anonymous->stmts, New_::class) as $construction) {
                if ($this->namesTheAnonymousClass($construction->class)) {
                    $ids[spl_object_id($construction)] = true;
                }
            }
        }

        return $ids;
    }

    // because `self::class`, `static::class` and `parent::class` name the same class as `self`, `static` and `parent`
    private function namesTheAnonymousClass(Name|Expr|Class_ $class): bool
    {
        if ($class instanceof ClassConstFetch) {
            return $class->class instanceof Name && $class->name instanceof Identifier && 'class' === $class->name->toLowerString() && $class->class->isSpecialClassName();
        }

        return $class instanceof Name && $class->isSpecialClassName();
    }

    // null because `self`, `static` and `parent` name the using class, and the trait's scope is its file's, not its method's, so only a literal class name is known there and any other class may be a carrier
    /**
     * @return list<string>|null
     */
    private function traitClassNamesOf(Name|Expr|Class_ $class): ?array
    {
        return match (true) {
            $class instanceof Class_ => [],
            $class instanceof Name => $class->isSpecialClassName() ? null : [$class->toString()],
            $class instanceof String_ => [$this->classNames->withoutLeadingBackslash($class->value)],
            $class instanceof ClassConstFetch && $class->class instanceof Name && !$class->class->isSpecialClassName() && $class->name instanceof Identifier && 'class' === $class->name->toLowerString() => [$class->class->toString()],
            default => null,
        };
    }
}
