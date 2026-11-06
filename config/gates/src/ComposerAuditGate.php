<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class ComposerAuditGate implements Gate
{
    public function name(): string
    {
        return 'audit';
    }

    public function run(Workspace $workspace): GateResult
    {
        $ignores = $this->auditConfiguration($workspace);

        if ([] !== $ignores) {
            return GateResult::withFindings($this->name(), $ignores);
        }

        $result = $this->audit($workspace);

        if (!$result instanceof ProcessResult) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch COMPOSER_HOME for the audit');
        }

        if ($result->succeeded()) {
            return GateResult::passed($this->name());
        }

        $findings = $this->findings($result->output);

        return GateResult::withFindings($this->name(), [] === $findings ? ['composer audit failed: '.$result->firstErrorLine()] : $findings);
    }

    private function audit(Workspace $workspace): ?ProcessResult
    {
        $home = ScratchDirectory::create('intakt-composer-home');

        if (!$home instanceof ScratchDirectory) {
            return null;
        }

        try {
            return $workspace->command(
                ['composer', 'audit', '--locked', '--abandoned=fail', '--format=json', '--no-interaction'],
                ['COMPOSER_HOME' => $home->path, 'COMPOSER_AUDIT_ABANDONED' => 'fail'],
            );
        } finally {
            $home->remove();
        }
    }

    /**
     * @return list<string>
     */
    private function auditConfiguration(Workspace $workspace): array
    {
        $path = $workspace->root.'/composer.json';
        $manifest = is_file($path) ? JsonNode::parse((string) file_get_contents($path)) : null;

        if (!$manifest instanceof JsonNode) {
            return [];
        }

        return array_map(
            static fn (string $setting): string => 'composer.json config.audit.'.$setting.' hides findings from the audit',
            array_keys($manifest->get('config')->get('audit')->entries()),
        );
    }

    /**
     * @return list<string>
     */
    private function findings(string $output): array
    {
        $report = JsonNode::parse($output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        $findings = [];

        foreach ($report->get('advisories')->entries() as $package => $advisories) {
            foreach ($advisories->items() as $advisory) {
                $findings[] = $package.' '.$advisory->get('advisoryId')->text().' '.$advisory->get('title')->text();
            }
        }

        foreach ($report->get('abandoned')->entries() as $package => $replacement) {
            $findings[] = $package.' is abandoned, replacement: '.('' === $replacement->text() ? 'none' : $replacement->text());
        }

        return $findings;
    }
}
