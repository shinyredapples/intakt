<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\BareZizmorIgnores;
use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(BareZizmorIgnores::class)]
#[CoversClass(WorkflowsGate::class)]
final class BareZizmorIgnoresTest extends SandboxTestCase
{
    private const string TAGGED_CHECKOUT = 'uses: actions/checkout@v4';

    public function testABareIgnoreFailsTheGate(): void
    {
        self::assertSame(
            '.github/workflows/t.yml:8 zizmor ignore without a reason: # zizmor: ignore[unpinned-uses,artipacked]',
            $this->findingsOf(self::TAGGED_CHECKOUT.' # zizmor: ignore[unpinned-uses,artipacked]'),
        );
    }

    public function testAnIgnoreFollowedOnlyByBlanksFailsTheGate(): void
    {
        self::assertStringContainsString('t.yml:8 zizmor ignore without a reason', $this->findingsOf(self::TAGGED_CHECKOUT.' # zizmor: ignore[unpinned-uses]   '));
    }

    public function testAnIgnoreFollowedOnlyByPunctuationFailsTheGate(): void
    {
        self::assertStringContainsString('t.yml:8 zizmor ignore without a reason', $this->findingsOf(self::TAGGED_CHECKOUT.' # zizmor: ignore[unpinned-uses] # -'));
    }

    public function testASecondBareIgnoreDoesNotCountAsTheReasonOfTheFirst(): void
    {
        self::assertStringContainsString('t.yml:8 zizmor ignore without a reason', $this->findingsOf(self::TAGGED_CHECKOUT.' # zizmor: ignore[unpinned-uses] # zizmor: ignore[artipacked]'));
    }

    public function testDirectiveTextInAQuotedValueCannotBeTheReasonOfABareIgnoreInTheComment(): void
    {
        self::assertSame(
            '.github/workflows/t.yml:8 zizmor ignore without a reason: # zizmor: ignore[unpinned-uses,artipacked]',
            $this->findingsOf("{name: '# zizmor: ignore[foo] harmless', ".self::TAGGED_CHECKOUT.'} # zizmor: ignore[unpinned-uses,artipacked]'),
        );
    }

    public function testDirectiveTextInAQuotedValueWithNoWordsAfterItIsReportedToo(): void
    {
        self::assertSame(
            '.github/workflows/t.yml:8 zizmor ignore without a reason: # zizmor: ignore[artipacked]',
            $this->findingsOf("name: '# zizmor: ignore[artipacked]'\n        run: true"),
        );
    }

    public function testABareIgnoreInAYamlFileOutsideTheWorkflowsFailsTheGate(): void
    {
        $sandbox = $this->sandboxWithStep('run: true');
        $sandbox->write('.github/actions/a/action.yaml', "name: a\ndescription: a\nruns:\n  using: composite\n  steps:\n    - run: echo a # zizmor: ignore[template-injection]\n      shell: bash\n");

        self::assertSame(
            '.github/actions/a/action.yaml:6 zizmor ignore without a reason: # zizmor: ignore[template-injection]',
            $sandbox->check('--only=workflows')->findingsOf('workflows'),
        );
    }

    public function testTheNextLineOfAWorkflowWithCarriageReturnLineEndingsIsNotTheReasonOfABareIgnore(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', "name: t\ron: pull_request\rpermissions: {}\rjobs:\r  t:\r    runs-on: ubuntu-latest\r    steps:\r      - ".self::TAGGED_CHECKOUT." # zizmor: ignore[unpinned-uses,artipacked]\r      - run: echo hello\r");

        self::assertStringContainsString('t.yml:8 zizmor ignore without a reason', $sandbox->check('--only=workflows')->findingsOf('workflows'));
    }

    public function testTheNextLineOfAWorkflowWithWindowsLineEndingsIsNotTheReasonOfABareIgnore(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', "name: t\r\non: pull_request\r\npermissions: {}\r\njobs:\r\n  t:\r\n    runs-on: ubuntu-latest\r\n    steps:\r\n      - ".self::TAGGED_CHECKOUT." # zizmor: ignore[unpinned-uses,artipacked]\r\n      - run: echo hello\r\n");

        self::assertStringContainsString('t.yml:8 zizmor ignore without a reason', $sandbox->check('--only=workflows')->findingsOf('workflows'));
    }

    public function testAnUnreadableYamlFileFailsTheGate(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->sandboxWithStep('run: true');
        $sandbox->write('.github/dependabot.yml', "version: 2\nupdates: []\n");
        chmod($sandbox->root.'/.github/dependabot.yml', 0o000);

        self::assertStringContainsString('.github/dependabot.yml cannot be read for zizmor ignores', $sandbox->check('--only=workflows')->findingsOf('workflows'));
    }

    public function testAnIgnoreWithAReasonOnTheSameLinePassesTheGate(): void
    {
        self::assertTrue($this->sandboxWithStep(self::TAGGED_CHECKOUT.' # zizmor: ignore[unpinned-uses,artipacked] the tag is enough for this test')->check('--only=workflows')->passed('workflows'));
    }

    public function testACommentThatOnlyNamesZizmorPassesTheGate(): void
    {
        self::assertTrue($this->sandboxWithStep('run: true # zizmor checks this step')->check('--only=workflows')->passed('workflows'));
    }

    public function testTheRepositorysOwnWorkflowsPassTheGate(): void
    {
        $sandbox = $this->emptySandbox();
        new Filesystem()->mirror(dirname(__DIR__, 2).'/.github', $sandbox->root.'/.github');

        self::assertTrue($sandbox->check('--only=workflows')->passed('workflows'));
    }

    private function findingsOf(string $step): string
    {
        return $this->sandboxWithStep($step)->check('--only=workflows')->findingsOf('workflows');
    }

    private function sandboxWithStep(string $step): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', "name: t\non: pull_request\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - {$step}\n");

        return $sandbox;
    }
}
