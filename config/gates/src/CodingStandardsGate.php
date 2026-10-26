<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CodingStandardsGate extends PhpFilesToolGate
{
    public function name(): string
    {
        return 'cs';
    }

    protected function tool(): string
    {
        return 'php-cs-fixer';
    }

    protected function arguments(Workspace $workspace, array $files): array
    {
        return [
            'fix', '--dry-run', '-v', '--format=json', '--no-interaction', '--using-cache=no',
            '--config='.$workspace->config('php-cs-fixer.php'),
            ...$files,
        ];
    }

    protected function findings(ProcessResult $result): array
    {
        $report = JsonNode::parse($result->output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        return array_map(
            static fn (JsonNode $file): string => $file->get('name')->text().': '.implode(', ', array_map(static fn (JsonNode $fixer): string => $fixer->text(), $file->get('appliedFixers')->items())),
            $report->get('files')->items(),
        );
    }
}
