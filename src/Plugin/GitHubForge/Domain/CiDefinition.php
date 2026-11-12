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
    // Kept this strict because any other folder (an expression, quotes, a parent step) may point anywhere, so it must widen the match.
    private const string PLAIN_DIRECTORY = '#^(?!.*(?:^|/)\.\.(?:/|$))[\w.-]+(?:/[\w.-]+)*/?$#';

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

        return array_any($workflows, fn (WorkflowFile $workflow): bool => $this->names($workflow, $path));
    }

    // A workflow that cannot be read may name any path.
    private function names(WorkflowFile $workflow, string $path): bool
    {
        if (null === $workflow->content) {
            return true;
        }

        return array_any(
            $this->namesFor($workflow->content, $path),
            static fn (string $name): bool => 1 === preg_match('/(?<![\w.-])'.preg_quote($name, '/').'(?![\w.\/-])/', $workflow->content),
        );
    }

    // A step run from a working directory names its files relative to it; a directory this cannot read could be any folder of the path.
    /** @return list<string> */
    private function namesFor(string $content, string $path): array
    {
        preg_match_all(self::WORKING_DIRECTORY, $content, $directories);
        $names = [$path];
        foreach ($directories[1] as $directory) {
            if (1 !== preg_match(self::PLAIN_DIRECTORY, $directory)) {
                return [...$names, ...$this->suffixes($path)];
            }
            $prefix = trim(preg_replace('#^(\./)+#', '', $directory) ?? $directory, '/').'/';
            if (str_starts_with($path, $prefix)) {
                $names[] = substr($path, strlen($prefix));
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function suffixes(string $path): array
    {
        $parts = explode('/', $path);

        return array_map(static fn (int $start): string => implode('/', array_slice($parts, $start)), array_keys($parts));
    }
}
