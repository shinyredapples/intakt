<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Override;

final readonly class RectorGate extends PhpFilesToolGate
{
    public const string PATHS_FILE_VARIABLE = 'INTAKT_RECTOR_PATHS_FILE';

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

    // Rector hands its paths to each parallel worker inside one shell command string, which Linux caps at 128 KiB, so a full run over every file failed to start its workers ("Argument list too long"); a file named by the environment carries any number of paths.
    #[Override]
    protected function environment(PhpFileSelection $selection): array
    {
        $list = implode("\n", $selection->files)."\n";
        $directory = GatesCache::directory().'/rector-paths';
        $file = $directory.'/'.hash('xxh128', $list).'.txt';

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new ScopeFailure('cannot create '.$directory);
        }

        if (!is_file($file) && false === file_put_contents($file, $list, LOCK_EX)) {
            throw new ScopeFailure('cannot write '.$file);
        }

        return [self::PATHS_FILE_VARIABLE => $file];
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
