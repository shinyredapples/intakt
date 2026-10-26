<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class ArchitectureGate implements Gate
{
    private const array CODE_DIRECTORIES = ['src'];

    public function name(): string
    {
        return 'architecture';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!$this->hasCode($workspace)) {
            return GateResult::passed($this->name());
        }

        if (!$workspace->hasTool('phparkitect')) {
            return GateResult::failedBecause($this->name(), 'missing tool phparkitect');
        }

        $result = $workspace->command([
            $workspace->tool('phparkitect'), 'check', '--format=json', '--skip-baseline', '--no-interaction',
            '--config='.$workspace->config('arkitect.php'),
        ]);

        return $this->resultOf($result);
    }

    private function hasCode(Workspace $workspace): bool
    {
        foreach (self::CODE_DIRECTORIES as $directory) {
            if (is_dir($workspace->root.'/'.$directory)) {
                return true;
            }
        }

        return false;
    }

    private function resultOf(ProcessResult $result): GateResult
    {
        if ($result->succeeded()) {
            return GateResult::passed($this->name());
        }

        $findings = $this->findings($result->output);

        return GateResult::withFindings($this->name(), [] === $findings ? ['tool failed: '.$result->firstErrorLine()] : $findings);
    }

    /**
     * @return list<string>
     */
    private function findings(string $output): array
    {
        $report = JsonNode::parse($this->jsonIn($output));
        $findings = [];

        foreach ($report instanceof JsonNode ? $report->get('details')->entries() : [] as $className => $violations) {
            foreach ($violations->items() as $violation) {
                $findings[] = $className.' line '.$violation->get('line')->text().': '.$violation->get('error')->text();
            }
        }

        return $findings;
    }

    private function jsonIn(string $output): string
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        return false === $start || false === $end ? '' : substr($output, $start, $end - $start + 1);
    }
}
