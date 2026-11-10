<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class WorkflowsGate implements Gate
{
    // Because no network, token or config file of the tree may change the answer.
    private const array SCAN = ['zizmor', '--offline', '--no-config', '--quiet', '--format=json', '.github'];
    private const array NO_TOKENS = ['GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'ZIZMOR_GITHUB_TOKEN' => false];

    public function name(): string
    {
        return 'workflows';
    }

    public function run(Workspace $workspace): GateResult
    {
        $result = $workspace->command(self::SCAN, self::NO_TOKENS);

        if (127 === $result->exitCode) {
            return GateResult::failedBecause('workflows', 'missing tool zizmor');
        }

        $findings = array_map($this->finding(...), JsonNode::parse($result->output)?->items() ?? []);

        return $result->succeeded() ? GateResult::passed('workflows') : GateResult::withFindings('workflows', [] !== $findings ? $findings : ['zizmor failed: '.$result->firstErrorLine()]);
    }

    private function finding(JsonNode $finding): string
    {
        $location = $finding->get('locations')->get('0');
        $row = (int) $location->get('concrete')->get('location')->get('start_point')->get('row')->text();

        return $location->get('symbolic')->get('key')->get('Local')->get('verbatim_path')->text().':'.($row + 1).' '.$finding->get('ident')->text().': '.$finding->get('desc')->text();
    }
}
