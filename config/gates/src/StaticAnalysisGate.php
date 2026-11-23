<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Override;

final readonly class StaticAnalysisGate extends PhpFilesToolGate
{
    public const int MEMORY_LIMIT_GIB = 2;

    public const string CHANGED_FILES_ONLY_VARIABLE = 'INTAKT_PHPSTAN_CHANGED_FILES_ONLY';

    public function name(): string
    {
        return 'phpstan';
    }

    protected function tool(): string
    {
        return 'phpstan';
    }

    protected function arguments(Workspace $workspace, array $files): array
    {
        return [
            'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit='.self::MEMORY_LIMIT_GIB.'G',
            '--configuration='.$workspace->config('phpstan.neon'),
            ...$files,
        ];
    }

    // PHPStan cannot tell a changed-files subset from a full file list (both are files on its command line), so the gate tells the rules that need every using class, and removes a value the caller exported so that only the gate can switch a check off.
    #[Override]
    protected function environment(PhpFileSelection $selection): array
    {
        return [self::CHANGED_FILES_ONLY_VARIABLE => $selection->isSubset() ? '1' : false];
    }

    protected function findings(ProcessResult $result): array
    {
        $report = JsonNode::parse($result->output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        $findings = array_map(static fn (JsonNode $error): string => $error->text(), $report->get('errors')->items());

        foreach ($report->get('files')->entries() as $path => $file) {
            foreach ($file->get('messages')->items() as $message) {
                $findings[] = $path.':'.$message->get('line')->text().' '.$message->get('identifier')->text().' '.$message->get('message')->text();
            }
        }

        return $findings;
    }
}
