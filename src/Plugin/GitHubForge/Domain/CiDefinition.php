<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// A file a workflow runs runs on the runner as the workflow does, so changing it is a CI change like editing the workflow.
final readonly class CiDefinition
{
    private const string GITHUB_FOLDER = '.github/';
    // Every Dockerfile counts, because a workflow can build an image from one in its build context without naming it.
    private const string DOCKERFILE = '/(?:^|\/)(?:Dockerfile(?:\..+)?|[^\/]+\.Dockerfile)$/';
    private const string WORKING_DIRECTORY = '/working-directory:[ \t]*(\S*)/';
    private const string USES = '/uses:[ \t]*(\S*)/';
    private const string LOCAL_ACTION = './';
    // Kept this strict because any other folder (an expression, an unclosed quote, none on its line) may point anywhere, so it must widen the match.
    private const string PLAIN_FOLDER = '#^(?:[\w.-]+(?:/[\w.-]+)*/?)?$#';
    // A step path is not resolved against its folder: a dot component anywhere (../test.sh, cd .., scripts/.) may name any file, so it widens the match too. A leading ./ names the folder itself.
    private const string DOT_COMPONENT = '#/\.(?![\w.-])|(?<![\w.-])\.\.(?![\w.-])#';

    /**
     * @param list<WorkflowFile> $workflows
     * @param list<string>       $changedPaths
     *
     * @return list<string>
     */
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

    // A workflow that cannot be read may name any path.
    private function runs(WorkflowFile $workflow, string $path): bool
    {
        if (null === $workflow->content) {
            return true;
        }

        return $this->runsAsLocalAction($workflow->content, $path) || $this->names($workflow->content, $path);
    }

    // Every file under a local action's folder, because its action.yml may run any of them.
    private function runsAsLocalAction(string $content, string $path): bool
    {
        preg_match_all(self::USES, $content, $uses);
        foreach ($uses[1] as $raw) {
            $action = new WorkflowValue($raw)->text();
            if (!str_starts_with($action, self::LOCAL_ACTION)) {
                continue;
            }
            $folder = $this->folderOf($action);
            if (null === $folder || str_starts_with($path, $folder)) {
                return true;
            }
        }

        return false;
    }

    private function names(string $content, string $path): bool
    {
        return array_any(
            $this->namesFor($content, $path),
            static fn (string $name): bool => 1 === preg_match('/(?<![\w.-])'.preg_quote($name, '/').'(?![\w.\/-])/', $content),
        );
    }

    // A step run from a working directory names its files relative to it; a folder this cannot read could be any folder of the path.
    /** @return list<string> */
    private function namesFor(string $content, string $path): array
    {
        preg_match_all(self::WORKING_DIRECTORY, $content, $directories);
        $folders = array_map(fn (string $raw): ?string => $this->folderOf(new WorkflowValue($raw)->text()), $directories[1]);
        if (in_array(null, $folders, true) || 1 === preg_match(self::DOT_COMPONENT, $content)) {
            return $this->suffixes($path);
        }
        $names = [$path];
        foreach ($folders as $folder) {
            if ('' !== $folder && str_starts_with($path, $folder)) {
                $names[] = substr($path, strlen($folder));
            }
        }

        return $names;
    }

    // Null when the folder is not a plain relative one; the empty prefix is the repository root.
    private function folderOf(string $value): ?string
    {
        $folder = preg_replace('#^(?:\./)+#', '', $value) ?? $value;
        if (1 !== preg_match(self::PLAIN_FOLDER, $folder)) {
            return null;
        }

        return '' === $folder ? '' : rtrim($folder, '/').'/';
    }

    /** @return list<string> */
    private function suffixes(string $path): array
    {
        $parts = explode('/', $path);

        return array_map(static fn (int $start): string => implode('/', array_slice($parts, $start)), array_keys($parts));
    }
}
