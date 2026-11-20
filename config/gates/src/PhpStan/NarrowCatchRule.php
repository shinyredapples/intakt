<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Error;
use Exception;
use InvalidArgumentException;
use LogicException;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RuntimeException;
use Throwable;

/**
 * @implements Rule<TryCatch>
 */
final readonly class NarrowCatchRule implements Rule
{
    private const array ROOTS = [Exception::class, Error::class, RuntimeException::class, LogicException::class, InvalidArgumentException::class];

    // a one-line comment that names what the boundary protects, so a bare "Boundary:" does not count
    private const string BOUNDARY = '~^//\s*Boundary:\s*\S~';

    private const string SOURCE = '/src/';

    public function __construct(
        private string $root,
    ) {
    }

    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_starts_with($scope->getFile(), $this->root.self::SOURCE)) {
            return [];
        }

        $findings = [];
        $before = $node->stmts;

        foreach ($node->catches as $catch) {
            foreach ($this->genericTypesOf($catch, $scope) as $type) {
                $findings[] = $this->findingFor($type, $catch, $before);
            }

            $before = $catch->stmts;
        }

        return array_values(array_filter($findings));
    }

    /**
     * @return list<string>
     */
    private function genericTypesOf(Catch_ $catch, Scope $scope): array
    {
        $names = array_map(static fn (Name $type): string => ltrim($scope->resolveName($type), '\\'), $catch->types);

        return array_values(array_filter($names, $this->isGeneric(...)));
    }

    // in any case, because PHP resolves class names case-insensitively and \runtimeexception catches the same class
    private function isGeneric(string $name): bool
    {
        return in_array(strtolower($name), array_map(strtolower(...), [Throwable::class, ...self::ROOTS]), true);
    }

    /**
     * @param array<Stmt> $before
     */
    private function findingFor(string $type, Catch_ $catch, array $before): ?IdentifierRuleError
    {
        $isThrowable = 0 === strcasecmp($type, Throwable::class);

        if ($isThrowable && $this->hasBoundaryCommentAbove($catch, $before)) {
            return null;
        }

        $message = $isThrowable
            ? sprintf('A catch of %s outside a failure boundary: name the failures the code expects, or, where it rethrows or records the failure, put a "// Boundary: <what it protects>" line directly above the catch.', Throwable::class)
            : sprintf('A catch of %s: name the failures the code expects (a library\'s own exception base or a domain exception); only %s may be caught, and only at a failure boundary.', $type, Throwable::class);

        return RuleErrorBuilder::message($message)
            ->identifier('intakt.narrowCatch')
            ->line($catch->getStartLine())
            ->build();
    }

    // with "} catch" the comment above the catch closes the block before it and becomes a trailing Nop there; otherwise it belongs to the catch itself
    /**
     * @param array<Stmt> $before
     */
    private function hasBoundaryCommentAbove(Catch_ $catch, array $before): bool
    {
        $last = array_last($before);
        $comments = [...$catch->getComments(), ...($last instanceof Nop ? $last->getComments() : [])];

        return array_any(
            $comments,
            static fn (Comment $comment): bool => $comment->getEndLine() === $catch->getStartLine() - 1 && 1 === preg_match(self::BOUNDARY, $comment->getText()),
        );
    }
}
