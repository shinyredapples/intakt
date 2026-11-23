<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentsGate extends PhpFilesGate
{
    public function __construct(private CommentRule $rule = new CommentRule())
    {
    }

    public function name(): string
    {
        return 'comments';
    }

    protected function check(Workspace $workspace, PhpFileSelection $selection): GateResult
    {
        $findings = [];

        foreach ($selection->files as $path) {
            foreach ($this->violationsIn($workspace->root.'/'.$path) as $violation) {
                $findings[] = $path.' '.$violation;
            }
        }

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function violationsIn(string $file): array
    {
        $source = file_get_contents($file);

        return false === $source ? ['unreadable'] : $this->rule->violations($source);
    }
}
