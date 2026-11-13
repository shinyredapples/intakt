<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

// A file a workflow runs runs on the runner as the workflow does, so changing it is a CI change like editing the workflow.
final readonly class CiDefinition
{
    private const string GITHUB_FOLDER = '.github/';
    // Every Dockerfile counts, because a workflow can build an image from one in its build context without naming it.
    private const string DOCKERFILE = '/(?:^|\/)(?:Dockerfile(?:\..+)?|[^\/]+\.Dockerfile)$/i';
    // A quoted key or a space before the colon is the same YAML key, so both still name an action.
    private const string USES = '/uses[\'"]?[ \t]*:[ \t]*(\S*)/i';
    private const string QUOTED = '/^([\'"])(.*)\1$/';
    // The owner never starts with a dot, so ./tools/x@v1 and ../x@v1 stay local actions.
    private const string REMOTE_ACTION = '#^(?:docker://\S+|[\w-][\w.-]*/[\w./-]+@[\w./-]+)$#';
    private const string LOCAL_ACTION = './';
    private const string PLAIN_FOLDER = '#^(?:(?!\.\.?/)[\w.@-]+/)*$#';

    public function __construct(
        private WorkflowReader $reader,
    ) {
    }

    /**
     * @param list<WorkflowFile> $workflows
     * @param list<string>       $changedPaths
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function changedAmong(array $workflows, array $changedPaths): array
    {
        return array_values(array_filter($changedPaths, fn (string $path): bool => $this->isPartOf($workflows, $path)));
    }

    /** @param list<WorkflowFile> $workflows */
    private function isPartOf(array $workflows, string $path): bool
    {
        if (str_starts_with($path, self::GITHUB_FOLDER) || 1 === preg_match(self::DOCKERFILE, $path)) {
            return true;
        }

        return array_any($workflows, fn (WorkflowFile $workflow): bool => $this->runs($workflow, $path));
    }

    // Paths are not resolved against a working directory or a cd, so naming wins over any folder; a workflow that cannot be read may name any path.
    private function runs(WorkflowFile $workflow, string $path): bool
    {
        if (null === $workflow->content || !$this->isReadable($workflow->content)) {
            return true;
        }

        return $this->runsAsLocalAction($workflow->content, $path) || $this->namesTheFileOf($workflow->content, $path);
    }

    // The rules below read the text as written, so a form the strict line reader refuses (an escaped key or value, an anchor, a tag) could spell a path the text does not show.
    private function isReadable(string $content): bool
    {
        try {
            $this->reader->workflow($content);
        } catch (WorkflowFormNotRead) {
            return false;
        }

        return true;
    }

    // Every file under a local action's folder, because its action.yml may run any of them without the workflow naming it; case is ignored as for file names.
    private function runsAsLocalAction(string $content, string $path): bool
    {
        preg_match_all(self::USES, $content, $uses);
        $actions = array_map($this->unquoted(...), $uses[1]);

        return array_any(
            array_filter($actions, static fn (string $action): bool => 1 !== preg_match(self::REMOTE_ACTION, $action)),
            fn (string $action): bool => str_starts_with(mb_strtolower($path), mb_strtolower($this->folderOf($action))),
        );
    }

    private function unquoted(string $value): string
    {
        return 1 === preg_match(self::QUOTED, $value, $quoted) ? $quoted[2] : $value;
    }

    // Answers the empty prefix, so every path counts, because ./ is the repository root and any other form (a value on the next line, an expression, a dot part) may be any folder.
    private function folderOf(string $action): string
    {
        if (!str_starts_with($action, self::LOCAL_ACTION)) {
            return '';
        }
        $folder = rtrim(substr($action, strlen(self::LOCAL_ACTION)), '/');
        $folder = '' === $folder ? '' : $folder.'/';

        return 1 === preg_match(self::PLAIN_FOLDER, $folder) ? $folder : '';
    }

    // Every trailing part of a path ends in its file name, so a whole-word match of the file name covers them all; case is ignored because hosted Windows and macOS runners ignore it too.
    private function namesTheFileOf(string $content, string $path): bool
    {
        $name = array_last(explode('/', $path));

        return 0 !== preg_match('/(?<![\w.-])'.preg_quote($name, '/').'(?![\w-]|\.[\w.-])/i', $content);
    }
}
