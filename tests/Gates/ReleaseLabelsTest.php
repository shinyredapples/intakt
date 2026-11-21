<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class ReleaseLabelsTest extends SandboxTestCase
{
    // The fake gh keeps the pull request's labels in a file, one per line, as the API would.
    private const string FAKE_GH = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        case "$1 $2" in
          'label create') exit 0 ;;
          'pr view') paste -sd, "$LABELS" ;;
          'pr edit') if [[ "$6" == --add-label ]]; then echo "$7" >> "$LABELS"; else grep -vx "$7" "$LABELS" > "$LABELS.new" || true; mv "$LABELS.new" "$LABELS"; fi ;;
          *) exit 2 ;;
        esac
        BASH;

    public function testTheTypeOfTheTitleBecomesTheOnlyTypeLabel(): void
    {
        $sandbox = $this->sandboxLabeled("docs\nwip\n");

        $result = $this->runWorkflow($sandbox, 'feat(review)!: add x');

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("wip\nfeat\n", file_get_contents($sandbox->root.'/labels'));
    }

    public function testATypeLabelOnThePullRequestIsRemovedWhenTheTitleNoLongerMatchesIt(): void
    {
        // A run this one cancelled may have added feat late, so the labels are read from the pull request itself.
        $sandbox = $this->sandboxLabeled("docs\nfeat\n");

        $this->runWorkflow($sandbox, 'fix: y');

        self::assertSame("fix\n", file_get_contents($sandbox->root.'/labels'));
    }

    public function testAFailingLabelCallFailsTheRun(): void
    {
        $sandbox = $this->sandboxLabeled('');
        $sandbox->write('bin/gh', "#!/usr/bin/env bash\nexit 1\n");

        self::assertFalse($this->runWorkflow($sandbox, 'docs: z')->succeeded());
    }

    public function testTheInstallStepInstallsUbuntusGhOnlyWhenTheRunnerLacksIt(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('calls', '');
        $sandbox->write('bin/sudo', "#!/bin/sh\necho \"sudo \$*\" >> \"\$CALLS\"\n");

        self::assertTrue($this->runInstallStep($sandbox)->succeeded());
        self::assertSame("sudo apt-get update -qq\nsudo apt-get install -y -qq gh\n", file_get_contents($sandbox->root.'/calls'));

        $sandbox->write('calls', '');
        $sandbox->write('bin/gh', "#!/bin/sh\n");
        self::assertTrue($this->runInstallStep($sandbox)->succeeded());
        self::assertSame('', file_get_contents($sandbox->root.'/calls'));
    }

    // Only the fakes are on PATH, so a gh installed on this machine stays out.
    private function runInstallStep(Sandbox $sandbox): ProcessResult
    {
        /** @var array{jobs: array{label: array{steps: list<array{run: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');

        return $sandbox->runScript('chmod +x bin/*; PATH="$PWD/bin"; '.$workflow['jobs']['label']['steps'][0]['run'], ['CALLS' => $sandbox->root.'/calls']);
    }

    private function sandboxLabeled(string $labels): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('labels', $labels);
        $sandbox->write('bin/gh', self::FAKE_GH);

        return $sandbox;
    }

    private function runWorkflow(Sandbox $sandbox, string $title): ProcessResult
    {
        /** @var array{jobs: array{label: array{steps: list<array{run: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');
        $environment = ['TITLE' => $title, 'REPOSITORY' => 'example/repo', 'NUMBER' => '7', 'LABELS' => $sandbox->root.'/labels'];

        return $sandbox->runScript('chmod +x bin/gh; PATH="$PWD/bin:$PATH"; '.$workflow['jobs']['label']['steps'][1]['run'], $environment);
    }
}
