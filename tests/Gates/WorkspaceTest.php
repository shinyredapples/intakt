<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\Mode;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGitCall::class)]
final class WorkspaceTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function gateTestInputs(): iterable
    {
        yield 'the gate configuration' => ['config/gates/phpstan.neon'];
        yield 'a gate class' => ['config/gates/src/Gate.php'];
        yield 'bin/check' => ['bin/check'];
        yield 'composer.json' => ['composer.json'];
        yield 'composer.lock' => ['composer.lock'];
        yield 'the application PHPUnit configuration' => ['phpunit.dist.xml'];
        yield 'the gates PHPUnit configuration' => ['phpunit.gates.xml'];
        yield 'a gate test' => ['tests/Gates/NewTest.php'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/src/New.php.fixture'];
        yield 'the CI image' => ['.github/ci/Dockerfile'];
        yield 'a workflow' => ['.github/workflows/ci.yml'];
        yield 'the showcase script' => ['docs/showcase/figures.sh'];
        yield 'another executable' => ['bin/probe'];
        yield 'the forge copy of the hardened git call' => ['src/Core/Git/Contract/HardenedGit.php'];
    }

    public function testAChangeOnlyToTheApplicationLeavesTheGateTestsOut(): void
    {
        $sandbox = $this->branchOffABase();
        $sandbox->write('src/Core/Report/Domain/Report.php', "<?php\n");
        $sandbox->commitAll('feat: add a report');

        self::assertFalse($this->workspace($sandbox, Mode::Full, 'main')->changesGateTests());
        self::assertFalse($this->workspace($sandbox, Mode::ChangedFilesOnly, 'main')->changesGateTests());
    }

    #[DataProvider('gateTestInputs')]
    public function testAChangeToAGateTestInputRunsTheGateTests(string $path): void
    {
        $sandbox = $this->branchOffABase();
        $sandbox->write($path, "changed\n");
        $sandbox->commitAll('chore: change '.$path);

        self::assertTrue($this->workspace($sandbox, Mode::Full, 'main')->changesGateTests());
    }

    public function testAnUncommittedGateTestRunsTheGateTests(): void
    {
        $sandbox = $this->branchOffABase();
        $sandbox->write('tests/Gates/NewTest.php', "<?php\n");

        self::assertTrue($this->workspace($sandbox, Mode::Full, 'main')->changesGateTests());
    }

    public function testTheBaseItselfRunsTheGateTests(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "base\n");
        $sandbox->commitAll('chore: start');

        self::assertTrue($this->workspace($sandbox, Mode::Full, 'main')->changesGateTests());
    }

    public function testAMissingBaseRunsTheGateTests(): void
    {
        $sandbox = $this->branchOffABase();

        self::assertTrue($this->workspace($sandbox, Mode::Full, 'missing')->changesGateTests());
    }

    public function testATreeWithoutGitRunsTheGateTests(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Report/Domain/Report.php', "<?php\n");

        self::assertTrue($this->workspace($sandbox, Mode::Full, null)->changesGateTests());
    }

    private function branchOffABase(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "base\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('README.md', "changed\n");
        $sandbox->commitAll('docs: change the readme');

        return $sandbox;
    }

    private function workspace(Sandbox $sandbox, Mode $mode, ?string $base): Workspace
    {
        return new Workspace($sandbox->root, dirname(__DIR__, 2), $mode, $base);
    }
}
