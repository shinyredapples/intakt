<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class RunOnCopyTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/run-on-copy.sh';

    // The script copies the fixed /workspace mount, so the fake cp only creates the copy's directory and never reads it.
    private const string FAKE_CP = <<<'SH'
        #!/bin/sh
        mkdir -p "$3"
        SH;

    private const string FAKE_COMPOSER = <<<'SH'
        #!/bin/sh
        if [ -n "${GH_TOKEN+set}" ]; then echo "composer saw GH_TOKEN"; exit 97; fi
        if [ -f "$HOME/fail-install" ]; then echo "install failed"; exit 1; fi
        : > "$HOME/installed"
        SH;

    public function testComposerInstallRunsWithoutTheIssueWriteTokenInItsEnvironment(): void
    {
        $sandbox = $this->sandboxWithFakeTools();

        $result = $this->runOnCopy($sandbox, 'true');

        self::assertTrue($result->succeeded(), $result->output.$result->errors);
        self::assertStringNotContainsString('composer saw GH_TOKEN', $result->output);
        self::assertFileExists($sandbox->root.'/home/installed');
    }

    public function testTheCommandAfterTheInstallStillReceivesTheToken(): void
    {
        $sandbox = $this->sandboxWithFakeTools();

        $result = $this->runOnCopy($sandbox, 'sh -c \'printf %s "$GH_TOKEN" > "$HOME/seen"\'');

        self::assertTrue($result->succeeded(), $result->output.$result->errors);
        self::assertSame('token-value', file_get_contents($sandbox->root.'/home/seen'));
    }

    public function testAFailingInstallStopsTheScriptBeforeTheCommandRuns(): void
    {
        $sandbox = $this->sandboxWithFakeTools();
        $sandbox->write('home/fail-install', '');

        $result = $this->runOnCopy($sandbox, 'sh -c \': > "$HOME/seen"\'');

        self::assertSame(1, $result->exitCode, $result->output.$result->errors);
        self::assertFileDoesNotExist($sandbox->root.'/home/seen');
    }

    private function sandboxWithFakeTools(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/php', "#!/bin/sh\nexit 0\n");
        $sandbox->write('bin/cp', self::FAKE_CP);
        $sandbox->write('bin/composer', self::FAKE_COMPOSER);
        foreach (['php', 'cp', 'composer'] as $tool) {
            chmod($sandbox->root.'/bin/'.$tool, 0o755);
        }
        $sandbox->write('home/.keep', '');

        return $sandbox;
    }

    private function runOnCopy(Sandbox $sandbox, string $command): ProcessResult
    {
        return $sandbox->runScript(
            'PATH="$PWD/bin:$PATH" sh '.escapeshellarg(self::SCRIPT).' '.$command,
            ['GH_TOKEN' => 'token-value', 'HOME' => $sandbox->root.'/home'],
        );
    }
}
