<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Override;

final readonly class RectorGate extends PhpFilesToolGate
{
    public function name(): string
    {
        return 'rector';
    }

    protected function tool(): string
    {
        return 'rector';
    }

    protected function arguments(Workspace $workspace, array $files): array
    {
        return [
            'process', '--dry-run', '--no-progress-bar', '--output-format=json',
            '--config='.$workspace->config('rector.php'),
        ];
    }

    #[Override]
    protected function environment(PhpFileSelection $selection): array
    {
        return [RectorPathsFile::VARIABLE => RectorPathsFile::written($selection->files)];
    }

    protected function findings(ProcessResult $result): array
    {
        $report = JsonNode::parse($result->output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        $findings = array_map(static fn (JsonNode $error): string => $error->get('file')->text().' '.$error->get('message')->text(), $report->get('errors')->items());

        foreach ($report->get('file_diffs')->items() as $diff) {
            $findings[] = $diff->get('file')->text().' would be changed by '.implode(', ', $this->rectorNames($diff));
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function rectorNames(JsonNode $diff): array
    {
        return array_map(
            static fn (JsonNode $rector): string => basename(str_replace('\\', '/', $rector->text())),
            $diff->get('applied_rectors')->items(),
        );
    }
}
