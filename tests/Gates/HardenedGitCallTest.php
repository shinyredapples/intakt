<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HardenedGitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class HardenedGitCallTest extends TestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = Sandbox::empty();
        $this->sandbox->startRepository();
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    public function testTheForgeCopyBuildsTheSameCommands(): void
    {
        self::assertSame(new HardenedGitCall()->command(['status']), new HardenedGit()->command(['status']));
        self::assertSame(new HardenedGitCall()->commandWithPathspecMagic(['status']), new HardenedGit()->commandWithPathspecMagic(['status']));
    }

    public function testTheForgeCopyBuildsTheSameEnvironment(): void
    {
        $this->sandbox->git('config', 'diff.evil.textconv', 'cat');
        $this->sandbox->git('config', 'filter.evil.clean', 'cat');

        self::assertSame(new HardenedGitCall()->environment($this->sandbox->root), new HardenedGit()->environment($this->sandbox->root));
    }

    public function testGitStartedByAnotherToolRunsNoTextconvDriverThatTheRepositoryAttributesName(): void
    {
        $marker = $this->sandbox->root.'/.git/textconv-ran';
        $this->sandbox->write('.gitattributes', "*.txt diff=evil\n");
        $this->sandbox->write('note.txt', "secret\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->git('config', 'diff.evil.textconv', 'touch '.$marker.'; echo hidden; true');

        $this->sandbox->run('git', 'log', '-p', '-1');
        self::assertFileExists($marker, 'the driver must run without hardening, or this test proves nothing');
        unlink($marker);

        $patch = $this->logWithTheHardenedEnvironment();
        self::assertFileDoesNotExist($marker);
        self::assertStringContainsString('+secret', $patch);
    }

    public function testGitStartedByAnotherToolReadsNoReplacementObject(): void
    {
        $this->sandbox->write('note.txt', "secret\n");
        $this->sandbox->commitAll('Add a note');
        $original = trim($this->sandbox->git('rev-parse', 'HEAD:note.txt')->output);
        $this->sandbox->write('decoy.txt', "decoy\n");
        $decoy = trim($this->sandbox->git('hash-object', '-w', 'decoy.txt')->output);
        $this->sandbox->git('replace', $original, $decoy);

        // Because the process that runs the tests may itself be hardened, and an inherited GIT_NO_REPLACE_OBJECTS would hide the replacement.
        $unhardened = new ProcessRunner()->run(['git', 'log', '-p', '-1'], $this->sandbox->root, ['GIT_NO_REPLACE_OBJECTS' => false]);
        self::assertStringContainsString('+decoy', $unhardened->output, 'the replacement must apply without hardening, or this test proves nothing');

        self::assertStringContainsString('+secret', $this->logWithTheHardenedEnvironment());
    }

    private function logWithTheHardenedEnvironment(): string
    {
        $result = new ProcessRunner()->run(['git', 'log', '-p', '-1'], $this->sandbox->root, new HardenedGitCall()->environment($this->sandbox->root));
        self::assertTrue($result->succeeded(), $result->errors);

        return $result->output;
    }
}
