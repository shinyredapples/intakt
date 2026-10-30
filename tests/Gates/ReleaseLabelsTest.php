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

    public function testALabelAddedAfterTheEventIsRemovedWhenTheTitleNoLongerMatchesIt(): void
    {
        // An older run, cancelled by this one, added feat after this event's labels were read.
        $sandbox = $this->sandboxLabeled("docs\nfeat\n");

        $this->runWorkflow($sandbox, 'fix: y', 'docs');

        self::assertSame("fix\n", file_get_contents($sandbox->root.'/labels'));
    }

    public function testAFailingLabelCallFailsTheRun(): void
    {
        $sandbox = $this->sandboxLabeled('');
        $sandbox->write('bin/gh', "#!/usr/bin/env bash\nexit 1\n");

        self::assertFalse($this->runWorkflow($sandbox, 'docs: z')->succeeded());
    }

    private function sandboxLabeled(string $labels): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('labels', $labels);
        $sandbox->write('bin/gh', self::FAKE_GH);

        return $sandbox;
    }

    private function runWorkflow(Sandbox $sandbox, string $title, string $eventLabels = ''): ProcessResult
    {
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');
        $environment = ['TITLE' => $title, 'CURRENT' => $eventLabels, 'REPOSITORY' => 'example/repo', 'NUMBER' => '7', 'LABELS' => $sandbox->root.'/labels'];

        return $sandbox->runScript('chmod +x bin/gh; PATH="$PWD/bin:$PATH"; '.$workflow['jobs']['label']['steps'][0]['run'], $environment);
    }
}
