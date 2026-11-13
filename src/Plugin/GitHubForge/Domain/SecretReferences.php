<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class SecretReferences
{
    // GitHub reads context names in any case; the job's own token is the one secret inside the envelope.
    private const string SECRET = '/\bsecrets\b(?!\s*\.\s*github_token\b)(?:\s*\.\s*[A-Za-z0-9_-]+)?/i';

    /**
     * @return list<WorkflowFinding>
     */
    public function in(WorkflowScalar|WorkflowMapping|WorkflowSequence $node): array
    {
        return match (true) {
            $node instanceof WorkflowScalar => $this->inText($node->text, $node->line),
            $node instanceof WorkflowSequence => array_merge([], ...array_map($this->in(...), $node->items)),
            default => array_merge([], ...array_map($this->inEntry(...), $node->entries)),
        };
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function inEntry(WorkflowEntry $entry): array
    {
        return [...$this->inText($entry->key, $entry->line), ...$this->in($entry->value)];
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function inText(string $text, int $line): array
    {
        preg_match_all(self::SECRET, $text, $matches);

        return array_map(
            static fn (string $reference): WorkflowFinding => new WorkflowFinding(WorkflowRisk::Secret, $line, sprintf('reads a repository secret (%s)', $reference)),
            $matches[0],
        );
    }
}
