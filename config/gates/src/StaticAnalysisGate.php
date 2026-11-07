<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class StaticAnalysisGate extends PhpFilesToolGate
{
    public const int MEMORY_LIMIT_GIB = 2;

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
