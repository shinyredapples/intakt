<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because only a `$( )` is read as a source of its own, here-documents wait by the number of open substitutions: a line end in a subshell or a `case` reads every body queued on that line.
final class ShellPendingDocuments
{
    /**
     * @var array<int, list<array{delimiter: string, joinsLines: bool, stripsTabs: bool}>>
     */
    private array $documents = [];

    public function __construct(private readonly ShellHereDocuments $bodies)
    {
    }

    // Because an unquoted empty delimiter, as in a lone `<<` before a line end, names no here-document, it queues no body.
    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     */
    public function add(int $scope, array $document): void
    {
        if ('' !== $document['delimiter'] || !$document['joinsLines']) {
            $this->documents[$scope][] = $document;
        }
    }

    // Because bash reads the bodies of the here-documents of a line right after that line ends, they are skipped there in their order.
    public function afterBodies(int $offset, int $scope): int
    {
        foreach ($this->documents[$scope] ?? [] as $document) {
            $offset = $this->bodies->afterBody($offset, $document);
        }

        unset($this->documents[$scope]);

        return $offset;
    }

    // Because a here-document opened in a substitution that closes on its own line, as in `$(cat <<EOF)`, is read at the enclosing line end, and copying the outer queue on every close would make many of them on one line quadratic, the inner documents are appended to it in place.
    public function closeScope(int $scope): void
    {
        foreach ($this->documents[$scope] ?? [] as $document) {
            $this->documents[$scope - 1][] = $document;
        }

        unset($this->documents[$scope]);
    }
}
