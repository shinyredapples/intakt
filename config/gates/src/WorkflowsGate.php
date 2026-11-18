<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class WorkflowsGate implements Gate
{
    // Because no network, token or config file may change the answer, and zizmor without --strict-collection skips a workflow it cannot parse.
    private const array SCAN = ['zizmor', '--offline', '--no-config', '--quiet', '--strict-collection', '--format=json', '.github'];
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

        $findings = [...$this->zizmorFindings($result), ...new BareZizmorIgnores()->in($workspace->root)];

        return [] === $findings ? GateResult::passed('workflows') : GateResult::withFindings('workflows', $findings);
    }

    /**
     * @return list<string>
     */
    private function zizmorFindings(ProcessResult $result): array
    {
        if ($result->succeeded()) {
            return [];
        }

        $findings = array_map($this->finding(...), JsonNode::parse($result->output)?->items() ?? []);

        return [] !== $findings ? $findings : [$this->failureOf($result)];
    }

    private function finding(JsonNode $finding): string
    {
        $location = $finding->get('locations')->get('0');
        $path = $location->get('symbolic')->get('key')->get('Local')->get('verbatim_path')->text();
        $row = $location->get('concrete')->get('location')->get('start_point')->get('row')->text();
        // Because a finding without a local file and line still fails the gate, it must not read as an empty path on line 1.
        $where = '' === $path || !is_numeric($row) ? 'outside a local file' : $path.':'.((int) $row + 1);

        return $where.' '.$finding->get('ident')->text().': '.$finding->get('desc')->text();
    }

    // Because zizmor may fail without printing anything, the exit code is the cause that is never empty.
    private function failureOf(ProcessResult $result): string
    {
        $error = $this->errorOf($result);

        return 'zizmor failed with exit code '.$result->exitCode.('' === $error ? '' : ': '.$error);
    }

    // On one line, because zizmor names the cause only on the lines after "fatal: no audit was performed".
    private function errorOf(ProcessResult $result): string
    {
        return preg_replace('/\s+/', ' ', trim($result->errors."\n".$result->output)) ?? $result->firstErrorLine();
    }
}
