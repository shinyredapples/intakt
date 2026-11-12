<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built per file, because it keeps the keys above the current line; a form it does not read is reported, never guessed.
#[Exclude]
final class WorkflowFileScan
{
    private const string EXPRESSION = '/\$\{\{(.*?)\}\}/';
    private const string SECRET = '/\bsecrets\b(?!\s*\.\s*GITHUB_TOKEN\b)/i';

    /** @var list<array{indent: int, key: string, valued: bool}> */
    private array $keys = [];
    private readonly WorkflowFindings $findings;
    private readonly WorkflowJobs $jobs;

    public function __construct(
        private readonly WorkflowFile $file,
    ) {
        $this->findings = new WorkflowFindings($file->path);
        $this->jobs = new WorkflowJobs($this->findings);
    }

    /** @return list<WorkflowRiskFinding> */
    public function findings(): array
    {
        $content = $this->file->content;
        if (null === $content || 1 !== preg_match('//u', $content)) {
            $this->findings->report(0, WorkflowRiskKind::Unreadable, 'the file cannot be read as UTF-8 text');

            return $this->findings->all();
        }

        foreach (new WorkflowLines()->read($content) as $line) {
            $this->readLine($line);
        }
        $this->jobs->finish();

        return $this->findings->all();
    }

    private function readLine(WorkflowLine $line): void
    {
        $this->checkExpressions($line);
        if (null !== $line->unreadable) {
            $this->findings->report($line->number, WorkflowRiskKind::Unreadable, $line->unreadable);
        }
        if ($line->node instanceof WorkflowNode) {
            $this->readNode($line->number, $line->node);
        }
    }

    private function checkExpressions(WorkflowLine $line): void
    {
        $count = preg_match_all(self::EXPRESSION, $line->text, $expressions);
        if ($count !== substr_count($line->text, '${{')) {
            $this->findings->report($line->number, WorkflowRiskKind::Unreadable, 'an expression that does not close on its line');
        }
        foreach ($expressions[1] as $expression) {
            $this->checkSecret($line->number, $expression);
        }
    }

    private function checkSecret(int $number, string $text): void
    {
        if (1 === preg_match(self::SECRET, $text)) {
            $this->findings->report($number, WorkflowRiskKind::Secret, 'reads a secret other than the read-only GITHUB_TOKEN');
        }
    }

    private function readNode(int $number, WorkflowNode $node): void
    {
        while ([] !== $this->keys && array_last($this->keys)['indent'] >= $node->indent) {
            array_pop($this->keys);
        }
        if (array_last($this->keys)['valued'] ?? false) {
            $this->findings->report($number, WorkflowRiskKind::Unreadable, 'a line under a key that already has a value');

            return;
        }
        if (null !== $node->key) {
            $this->enterKey($number, $node);
        }
        if (!$node->value->isReadable()) {
            $this->findings->report($number, WorkflowRiskKind::Unreadable, sprintf('a value this scan does not read: %s', $node->value->raw));

            return;
        }
        null === $node->key ? $this->readItem($number, $node->value) : $this->readSetting($number, $node);
    }

    // A block scalar counts as a value too, because once its text ends a deeper line is no child of its key.
    private function enterKey(int $number, WorkflowNode $node): void
    {
        $this->keys[] = ['indent' => $node->indent, 'key' => (string) $node->key, 'valued' => '' !== $node->value->raw];
        $this->jobs->record($number, $this->path());
        if ('secrets' === $node->key) {
            $this->findings->report($number, WorkflowRiskKind::Secret, 'passes secrets on');
        }
    }

    private function readItem(int $number, WorkflowValue $value): void
    {
        if ($this->path() === ['on']) {
            $this->readTrigger($number, $value->text());
        }
    }

    private function readSetting(int $number, WorkflowNode $node): void
    {
        if ('if' === $node->key) {
            $this->checkSecret($number, $node->value->raw);
        }
        $path = $this->path();
        $depth = count($path);
        match (true) {
            $path === ['on'] => $this->readTriggers($number, $node->value),
            2 === $depth && 'on' === $path[0] => $this->readTrigger($number, $path[1]),
            $path === ['permissions'] => $this->jobs->readWorkflowPermissions($number, $node->value->text()),
            2 === $depth && 'permissions' === $path[0] => $this->jobs->readWorkflowScope($number, $node),
            3 === $depth && 'jobs' === $path[0] => $this->readJobSetting($number, $path[1], $node),
            4 === $depth && 'jobs' === $path[0] && 'permissions' === $path[2] => $this->jobs->readScope($number, $path[1], $node),
            default => null,
        };
    }

    private function readTriggers(int $number, WorkflowValue $value): void
    {
        foreach ($value->listed() as $trigger) {
            $this->readTrigger($number, $trigger);
        }
    }

    private function readTrigger(int $number, string $trigger): void
    {
        if (null === BranchTrigger::tryFrom($trigger)) {
            $this->findings->report($number, WorkflowRiskKind::Trigger, sprintf('the trigger %s may run with the base repository\'s rights', $trigger));
        }
    }

    private function readJobSetting(int $number, string $job, WorkflowNode $node): void
    {
        match ($node->key) {
            'runs-on' => $this->readRunner($number, $node->value),
            'permissions' => $this->jobs->readPermissions($number, $job, $node->value->text()),
            'uses' => $this->findings->report($number, WorkflowRiskKind::Unreadable, sprintf('the job %s calls a reusable workflow, which runs where that workflow says', $job)),
            default => null,
        };
    }

    private function readRunner(int $number, WorkflowValue $value): void
    {
        if (str_contains($value->raw, '${{')) {
            $this->findings->report($number, WorkflowRiskKind::Unreadable, 'runs-on comes from a variable or an expression, whose value can change without a commit');

            return;
        }
        $labels = $value->listed();
        if ([] === $labels) {
            $this->findings->report($number, WorkflowRiskKind::Unreadable, 'runs-on names no label on its own line');
        }
        foreach ($labels as $label) {
            if (null === HostedRunnerLabel::tryFrom($label)) {
                $this->findings->report($number, WorkflowRiskKind::NotHostedRunner, sprintf('the runner label %s is not a GitHub-hosted one', $label));
            }
        }
    }

    /** @return list<string> */
    private function path(): array
    {
        return array_column($this->keys, 'key');
    }
}
