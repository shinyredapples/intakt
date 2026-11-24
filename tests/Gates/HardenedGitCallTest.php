<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RepositoryAttributesRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

// A namespace, because the forge's GitCall shares its short name with the gates' one and AGENTS.md allows no import alias.
#[CoversClass(HardenedGitCall::class)]
#[UsesClass(GitCall::class)]
#[UsesNamespace('Intakt\Core\Git\Contract')]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
final class HardenedGitCallTest extends TestCase
{
    // Because the process that runs the tests may itself be hardened, and an inherited variable would hide what the repository does.
    private const array UNHARDENED = ['GIT_NO_LAZY_FETCH' => false, 'GIT_ALLOW_PROTOCOL' => false];

    private Sandbox $sandbox;

    private string $workingDirectory;

    public function testARelativeDirectoryIsPinnedAsAnAbsoluteWorkTree(): void
    {
        $this->sandbox->write('note.txt', "public\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->write('note.txt', "changed\n");
        chdir(dirname($this->sandbox->root));

        $result = new ProcessRunner()->git(['status', '--short'], basename($this->sandbox->root));

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame(" M note.txt\n", $result->output);
    }

    public function testGitStartedByAnotherToolFetchesNoMissingObjectThroughAPromisorRemoteCommand(): void
    {
        $marker = $this->sandbox->root.'/.git/promisor-ran';
        $script = $this->sandbox->root.'/.git/promisor';
        $this->sandbox->write('.git/promisor', "#!/bin/sh\ntouch ".$marker."\nexit 1\n");
        chmod($script, 0o755);
        $this->sandbox->write('note.txt', "secret\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->promiseMissingObjectsFrom($script);
        $this->sandbox->removeObject(trim($this->sandbox->git('rev-parse', 'HEAD:note.txt')->output));

        new ProcessRunner()->run(['git', 'log', '-p', '-1'], $this->sandbox->root, self::UNHARDENED);
        self::assertFileExists($marker, 'the command must run without hardening, or this test proves nothing');
        unlink($marker);

        $result = new ProcessRunner()->run(['git', 'log', '-p', '-1'], $this->sandbox->root, new HardenedGitCall()->environment($this->sandbox->root));
        self::assertFileDoesNotExist($marker);
        self::assertFalse($result->succeeded(), 'a missing object must fail the call');
    }

    public function testTheForgeCopyBuildsTheSameCalls(): void
    {
        $gate = new HardenedGitCall()->call(['status'], $this->sandbox->root);
        $forge = new HardenedGit()->call($this->sandbox->root, 'status');

        self::assertSame($gate->command, [...$forge->command]);
        self::assertSame($gate->directory, $forge->directory);
        self::assertSame($gate->environment, [...$forge->environment]);
        self::assertSame(
            new HardenedGitCall()->callWithPathspecMagic(['status'], $this->sandbox->root)->command,
            [...new HardenedGit()->callWithPathspecMagic($this->sandbox->root, 'status')->command],
        );
    }

    public function testASha256RepositoryGetsTheEmptyTreeOfItsObjectFormat(): void
    {
        $this->sandbox->remove();
        $this->sandbox = Sandbox::empty();
        $this->sandbox->git('init', '--quiet', '--object-format=sha256');
        $this->sandbox->write('note.txt', "public\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->write('note.txt', "changed\n");

        $call = new HardenedGitCall()->call(['diff', '--name-only', 'HEAD'], $this->sandbox->root);
        $result = new ProcessRunner()->run($call->command, $call->directory, $call->environment);

        self::assertSame('6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321', $call->environment['GIT_ATTR_SOURCE']);
        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("note.txt\n", $result->output);
    }

    public function testTheForgeCopyBuildsTheSameEnvironment(): void
    {
        $this->sandbox->git('config', 'diff.evil.textconv', 'cat');
        $this->sandbox->git('config', 'filter.evil.clean', 'cat');

        self::assertSame(new HardenedGitCall()->environment($this->sandbox->root), [...new HardenedGit()->environment($this->sandbox->root)]);
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

    public function testGitStartedByAnotherToolFollowsNoGraftFileThatHidesACommit(): void
    {
        $this->sandbox->commitAll('Base');
        $base = trim($this->sandbox->git('rev-parse', 'HEAD')->output);
        $this->sandbox->write('note.txt', "secret\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->write('note.txt', "public\n");
        $this->sandbox->commitAll('Replace the note');
        $this->sandbox->write('.git/info/grafts', trim($this->sandbox->git('rev-parse', 'HEAD')->output).' '.$base."\n");

        self::assertStringNotContainsString('+secret', $this->sandbox->run('git', 'log', '-p')->output, 'the graft must hide the commit without hardening, or this test proves nothing');

        $result = new ProcessRunner()->run(['git', 'log', '-p'], $this->sandbox->root, new HardenedGitCall()->environment($this->sandbox->root));
        self::assertStringContainsString('+secret', $result->output);
    }

    public function testGitStartedByAnotherToolGetsNoEnvironmentWhenInfoAttributesCouldHideAFileAsBinary(): void
    {
        $this->sandbox->write('note.txt', "secret\n");
        $this->sandbox->commitAll('Add a note');
        $this->sandbox->write('.git/info/attributes', "*.txt -diff\n");

        self::assertStringContainsString('Binary files', $this->sandbox->run('git', 'log', '-p', '-1')->output, 'the attribute must hide the file without hardening, or this test proves nothing');

        try {
            new HardenedGitCall()->environment($this->sandbox->root);
            self::fail('A repository with info/attributes must be refused.');
        } catch (RepositoryAttributesRefused $refused) {
            self::assertStringContainsString('/.git/info/attributes exists', $refused->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->workingDirectory = (string) getcwd();
        $this->sandbox = Sandbox::empty();
        $this->sandbox->startRepository();
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->sandbox->remove();
    }

    private function logWithTheHardenedEnvironment(): string
    {
        $result = new ProcessRunner()->run(['git', 'log', '-p', '-1'], $this->sandbox->root, new HardenedGitCall()->environment($this->sandbox->root));
        self::assertTrue($result->succeeded(), $result->errors);

        return $result->output;
    }
}
