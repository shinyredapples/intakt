<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class WorkflowsGate implements Gate
{
    private const string FOLDER = '.github';

    // Because the gate must give the same answer without network or token, zizmor gets neither, and no config file in the tree can disable an audit.
    private const array SCAN = ['zizmor', '--offline', '--no-config', '--no-progress', '--format=json', self::FOLDER];
    private const array NO_TOKENS = ['GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'ZIZMOR_GITHUB_TOKEN' => false];

    public function name(): string
    {
        return 'workflows';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!is_dir($workspace->root.'/'.self::FOLDER)) {
            return GateResult::passedBecause($this->name(), 'no '.self::FOLDER.' folder');
        }

        $result = $workspace->command(self::SCAN, self::NO_TOKENS);

        if ($result->succeeded()) {
            return GateResult::passed($this->name());
        }

        $findings = $this->findings($result->output);

        return GateResult::withFindings($this->name(), [] === $findings ? ['zizmor failed: '.$result->firstErrorLine()] : $findings);
    }

    /**
     * @return list<string>
     */
    private function findings(string $report): array
    {
        $findings = JsonNode::parse($report);

        return array_map($this->finding(...), $findings instanceof JsonNode ? $findings->items() : []);
    }

    private function finding(JsonNode $finding): string
    {
        $location = $finding->get('locations')->items()[0] ?? new JsonNode(null);
        $path = $location->get('symbolic')->get('key')->get('Local')->get('verbatim_path')->text();
        $row = (int) $location->get('concrete')->get('location')->get('start_point')->get('row')->text();

        return $path.':'.($row + 1).' '.$finding->get('ident')->text().': '.$finding->get('desc')->text();
    }
}
