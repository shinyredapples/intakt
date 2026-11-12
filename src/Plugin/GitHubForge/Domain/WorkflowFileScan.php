<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// A strict reader of the plain block YAML workflows are written in: a form it does not know is reported, never guessed.
// Built per file, because it keeps the keys above the current line while it reads.
final class WorkflowFileScan
{
    private const string KEY = '/^(?<key>[A-Za-z0-9_][A-Za-z0-9_.\/-]*):(?:\s+(?<value>.*))?$/';
    private const string ITEM = '/^-(?: +|$)/';
    // YAML also breaks lines at these, so a line holding one hides lines this scan would not see.
    private const string OTHER_LINE_BREAK = '/[\r\x{85}\x{2028}\x{2029}]/u';
    private const string BLOCK_SCALAR = '/^[|>][+-]?$/';
    private const string FLOW_SEQUENCE = '/^\[[^\[\]{}]*\]$/';
    private const string EXPRESSION = '/\$\{\{(.*?)\}\}/';
    private const string SECRET = '/\bsecrets\b(?!\s*\.\s*GITHUB_TOKEN\b)/i';
    private const array READ_ONLY_PERMISSIONS = ['{}', 'read-all'];
    private const array READ_ONLY_SCOPE = ['read', 'none'];

    /** @var list<WorkflowRiskFinding> */
    private array $findings = [];
    /** @var list<array{indent: int, key: string, valued: bool}> */
    private array $keys = [];
    private bool $started = false;
    private ?int $blockParent = null;
    private ?int $blockContent = null;
    /** @var array<string, int> */
    private array $jobs = [];
    /** @var array<string, true> */
    private array $runners = [];
    /** @var array<string, true> */
    private array $callers = [];
    /** @var array<string, true> the empty key stands for the workflow */
    private array $permissions = [];
    /** @var array<string, int> */
    private array $scopesAwaited = [];

    public function __construct(
        private readonly WorkflowFile $file,
    ) {
    }

    /** @return list<WorkflowRiskFinding> */
    public function findings(): array
    {
        $content = $this->file->content;
        if ($content === null || preg_match('//u', $content) !== 1) {
            $this->report(0, WorkflowRiskKind::Unreadable, 'the file cannot be read as UTF-8 text');

            return $this->findings;
        }

        foreach (explode("\n", $content) as $index => $line) {
            $this->readLine($index + 1, $line);
        }
        $this->checkJobs();
        $this->checkScopesAwaited();

        return $this->findings;
    }

    private function readLine(int $number, string $line): void
    {
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }
        if (preg_match(self::OTHER_LINE_BREAK, $line) === 1) {
            $this->report($number, WorkflowRiskKind::Unreadable, 'a line break other than a newline');

            return;
        }
        $this->checkExpressions($number, $line);
        if ($this->isBlockContent($line)) {
            return;
        }
        $this->readStructure($number, $line);
    }

    private function checkExpressions(int $number, string $line): void
    {
        $count = preg_match_all(self::EXPRESSION, $line, $expressions);
        if ($count !== substr_count($line, '${{')) {
            $this->report($number, WorkflowRiskKind::Unreadable, 'an expression that does not close on its line');
        }
        foreach ($expressions[1] as $expression) {
            $this->checkSecret($number, $expression);
        }
    }

    private function checkSecret(int $number, string $text): void
    {
        if (preg_match(self::SECRET, $text) === 1) {
            $this->report($number, WorkflowRiskKind::Secret, 'reads a secret other than the read-only GITHUB_TOKEN');
        }
    }

    // Mirrors YAML: a block scalar holds the lines indented deeper than its key and at least as deep as its first line.
    private function isBlockContent(string $line): bool
    {
        if ($this->blockParent === null) {
            return false;
        }
        if (trim($line) === '') {
            return true;
        }
        $indent = $this->indentOf($line);
        if ($indent > $this->blockParent && $indent >= ($this->blockContent ??= $indent)) {
            return true;
        }
        $this->blockParent = null;
        $this->blockContent = null;

        return false;
    }

    private function readStructure(int $number, string $line): void
    {
        $indent = $this->indentOf($line);
        $content = rtrim($this->withoutComment(substr($line, $indent)), " \t");
        if ($content === '') {
            return;
        }
        if ($content === '---' && $indent === 0 && !$this->started) {
            return;
        }
        $this->started = true;
        if ($content[0] === "\t") {
            $this->report($number, WorkflowRiskKind::Unreadable, 'a tab in the indentation');

            return;
        }

        $item = false;
        while (preg_match(self::ITEM, $content, $dash) === 1) {
            $item = true;
            $indent += strlen($dash[0]);
            $content = substr($content, strlen($dash[0]));
        }
        $this->readNode($number, $indent, $content, $item);
    }

    private function readNode(int $number, int $indent, string $content, bool $item): void
    {
        $this->closeKeysFrom($indent);
        if (array_last($this->keys)['valued'] ?? false) {
            $this->report($number, WorkflowRiskKind::Unreadable, 'a line under a key that already has a value');

            return;
        }
        if (preg_match(self::KEY, $content, $match) === 1) {
            $this->readKey($number, $indent, $match['key'], trim($match['value'] ?? ''));

            return;
        }
        if ($item && $content !== '' && $this->isReadableValue($number, $indent, $content)) {
            $this->readItem($number, $this->unquoted($content));

            return;
        }
        if (!$item || $content === '') {
            $this->report($number, WorkflowRiskKind::Unreadable, 'a line that is neither a plain key nor a list item');
        }
    }

    private function closeKeysFrom(int $indent): void
    {
        while ($this->keys !== [] && array_last($this->keys)['indent'] >= $indent) {
            array_pop($this->keys);
        }
    }

    private function readKey(int $number, int $indent, string $key, string $value): void
    {
        // A block scalar counts as a value too: once its text ends, a deeper line is no child of its key.
        $this->keys[] = ['indent' => $indent, 'key' => $key, 'valued' => $value !== ''];
        $path = array_column($this->keys, 'key');
        $this->recordJobKey($number, $path);
        if ($key === 'secrets') {
            $this->report($number, WorkflowRiskKind::Secret, 'passes secrets on');
        }
        if (!$this->isReadableValue($number, $indent, $value)) {
            return;
        }
        if ($key === 'if') {
            $this->checkSecret($number, $value);
        }
        $this->readSetting($number, $path, $this->unquoted($value));
    }

    /** @param list<string> $path */
    private function recordJobKey(int $number, array $path): void
    {
        if ($path[0] !== 'jobs' || count($path) < 2 || count($path) > 3) {
            return;
        }
        $job = $path[1];
        match ($path[2] ?? null) {
            null => $this->jobs[$job] = $number,
            'runs-on' => $this->runners[$job] = true,
            'uses' => $this->callers[$job] = true,
            'permissions' => $this->permissions[$job] = true,
            default => null,
        };
    }

    private function isReadableValue(int $number, int $indent, string $value): bool
    {
        if (preg_match(self::BLOCK_SCALAR, $value) === 1) {
            $this->blockParent = $indent;

            return true;
        }
        $readable = match (true) {
            in_array($value, ['', '{}'], true) => true,
            str_starts_with($value, '[') => $this->isReadableFlowSequence($value),
            default => $this->isReadableScalar($value),
        };
        if (!$readable) {
            $this->report($number, WorkflowRiskKind::Unreadable, sprintf('a value this scan does not read: %s', $value));
        }

        return $readable;
    }

    private function isReadableFlowSequence(string $value): bool
    {
        if (preg_match(self::FLOW_SEQUENCE, $value) !== 1) {
            return false;
        }

        return array_all($this->items($value), $this->isReadableScalar(...));
    }

    // Anchors, aliases, tags, flow collections and reserved indicators all start with one of these characters.
    private function isReadableScalar(string $value): bool
    {
        $first = $value[0] ?? '';
        if (in_array($first, ['&', '*', '!', '%', '@', '`', '|', '>', '?', '{', '}', '[', ']', ','], true)) {
            return false;
        }

        return !in_array($first, ['"', "'"], true) || (strlen($value) > 1 && str_ends_with($value, $first));
    }

    /** @param list<string> $path */
    private function readSetting(int $number, array $path, string $value): void
    {
        $depth = count($path);
        match (true) {
            $path === ['on'] => $this->readTriggers($number, $value),
            $depth === 2 && $path[0] === 'on' => $this->readTrigger($number, $path[1]),
            $path === ['permissions'] => $this->readPermissions($number, '', $value),
            $depth === 2 && $path[0] === 'permissions' => $this->readScope($number, '', $value),
            $depth === 3 && $path[0] === 'jobs' => $this->readJobSetting($number, $path[1], $path[2], $value),
            $depth === 4 && $path[0] === 'jobs' && $path[2] === 'permissions' => $this->readScope($number, $path[1], $value),
            default => null,
        };
    }

    private function readItem(int $number, string $value): void
    {
        if (array_column($this->keys, 'key') === ['on']) {
            $this->readTrigger($number, $value);
        }
    }

    private function readTriggers(int $number, string $value): void
    {
        foreach ($this->listed($value) as $trigger) {
            $this->readTrigger($number, $trigger);
        }
    }

    private function readTrigger(int $number, string $trigger): void
    {
        if (BranchTrigger::tryFrom($trigger) === null) {
            $this->report($number, WorkflowRiskKind::Trigger, sprintf('the trigger %s may run with the base repository\'s rights', $trigger));
        }
    }

    private function readPermissions(int $number, string $owner, string $value): void
    {
        $this->permissions[$owner] = true;
        match (true) {
            $value === '' => $this->scopesAwaited[$owner] = $number,
            in_array($value, self::READ_ONLY_PERMISSIONS, true) => null,
            $value === 'write-all' => $this->report($number, WorkflowRiskKind::WritableToken, 'permissions: write-all'),
            default => $this->report($number, WorkflowRiskKind::Unreadable, sprintf('permissions this scan does not read: %s', $value)),
        };
    }

    private function readScope(int $number, string $owner, string $value): void
    {
        unset($this->scopesAwaited[$owner]);
        $scope = array_last($this->keys)['key'] ?? '';
        match (true) {
            in_array($value, self::READ_ONLY_SCOPE, true) => null,
            $value === 'write' => $this->report($number, WorkflowRiskKind::WritableToken, sprintf('the token may write %s', $scope)),
            default => $this->report($number, WorkflowRiskKind::Unreadable, sprintf('a permission this scan does not read: %s: %s', $scope, $value)),
        };
    }

    private function readJobSetting(int $number, string $job, string $setting, string $value): void
    {
        match ($setting) {
            'runs-on' => $this->readRunner($number, $value),
            'permissions' => $this->readPermissions($number, $job, $value),
            'uses' => $this->report($number, WorkflowRiskKind::Unreadable, sprintf('the job %s calls a reusable workflow, which runs where that workflow says', $job)),
            default => null,
        };
    }

    private function readRunner(int $number, string $value): void
    {
        if (str_contains($value, '${{')) {
            $this->report($number, WorkflowRiskKind::Unreadable, 'runs-on comes from a variable or an expression, whose value can change without a commit');

            return;
        }
        $labels = $this->listed($value);
        if ($labels === []) {
            $this->report($number, WorkflowRiskKind::Unreadable, 'runs-on names no label on its own line');

            return;
        }
        foreach ($labels as $label) {
            if (HostedRunnerLabel::tryFrom($label) === null) {
                $this->report($number, WorkflowRiskKind::NotHostedRunner, sprintf('the runner label %s is not a GitHub-hosted one', $label));
            }
        }
    }

    private function checkJobs(): void
    {
        foreach ($this->jobs as $job => $line) {
            if (isset($this->callers[$job])) {
                continue;
            }
            if (!isset($this->runners[$job])) {
                $this->report($line, WorkflowRiskKind::Unreadable, sprintf('the job %s names no runs-on', $job));
            }
            if (!isset($this->permissions['']) && !isset($this->permissions[$job])) {
                $this->report($line, WorkflowRiskKind::WritableToken, sprintf('the job %s declares no permissions, so its token gets the repository\'s default, which may write', $job));
            }
        }
    }

    private function checkScopesAwaited(): void
    {
        foreach ($this->scopesAwaited as $line) {
            $this->report($line, WorkflowRiskKind::Unreadable, 'permissions without a scope');
        }
    }

    /** @return list<string> */
    private function listed(string $value): array
    {
        if ($value === '') {
            return [];
        }
        if (!str_starts_with($value, '[')) {
            return [$value];
        }

        return array_map($this->unquoted(...), $this->items($value));
    }

    // A comma inside a quoted item splits it into two unclosed quotes, which the scan then refuses.
    /** @return list<string> */
    private function items(string $flowSequence): array
    {
        $items = array_map(trim(...), explode(',', substr($flowSequence, 1, -1)));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private function unquoted(string $value): string
    {
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && strlen($value) > 1 && str_ends_with($value, $quote)) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    // Quote-aware, so a # inside a quoted value stays; a quote inside a plain value only keeps more text, never less.
    private function withoutComment(string $content): string
    {
        $quote = null;
        $length = strlen($content);
        for ($at = 0; $at < $length; ++$at) {
            $char = $content[$at];
            if ($quote === '"' && $char === '\\') {
                ++$at;
            } elseif ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && ($at === 0 || ctype_space($content[$at - 1]))) {
                return substr($content, 0, $at);
            }
        }

        return $content;
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    private function report(int $line, WorkflowRiskKind $kind, string $detail): void
    {
        $this->findings[] = new WorkflowRiskFinding($this->file->path, $line, $kind, $detail);
    }
}
