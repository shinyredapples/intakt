<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
final class HardenedGitTest extends TestCase
{
    private const array EMPTY_COMMIT = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--allow-empty', '--quiet', '-m', 'Empty'];

    private const array SUBJECT = ['log', '-1', '--format=%s'];

    private const array SETTINGS = [
        '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'diff.external=', '-c', 'core.attributesFile=/dev/null',
        '-c', 'log.showSignature=false', '-c', 'gpg.program=/dev/null', '-c', 'gpg.ssh.program=/dev/null', '-c', 'gpg.x509.program=/dev/null',
        '-c', 'i18n.logOutputEncoding=UTF-8', '-c', 'core.ignoreCase=false',
    ];

    // Because the process that runs the tests may itself be hardened, and an inherited variable would hide what the repository does.
    private const array UNHARDENED = ['GIT_NO_LAZY_FETCH' => false, 'GIT_ALLOW_PROTOCOL' => false];

    private TemporaryRepository $repository;

    /** @var array<array-key, mixed> */
    private array $environment;

    private string $workingDirectory;

    public function testTheCommandDisablesHooksFsmonitorExternalDiffAttributesSignaturesAndOutputEncodings(): void
    {
        $command = new HardenedGit()->call(['status'], $this->repository->path)->command;

        self::assertSame(
            ['git', '--no-replace-objects', '--no-lazy-fetch', '--literal-pathspecs', '--attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904', ...self::SETTINGS, 'status'],
            $command,
        );
    }

    public function testTheMagicVariantKeepsTheHardeningButAllowsPathspecMagic(): void
    {
        $command = new HardenedGit()->callWithPathspecMagic(['ls-files'], $this->repository->path)->command;

        self::assertNotContains('--literal-pathspecs', $command);
        self::assertContains('--no-replace-objects', $command);
        self::assertContains('--no-lazy-fetch', $command);
        self::assertContains('--attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904', $command);
        self::assertContains('core.hooksPath=/dev/null', $command);
    }

    public function testTheEnvironmentTurnsLazyFetchesAndEveryTransportOff(): void
    {
        $_ENV['GIT_ALLOW_PROTOCOL'] = 'ext';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertSame('1', $environment['GIT_NO_LAZY_FETCH']);
        self::assertSame('', $environment['GIT_ALLOW_PROTOCOL']);
    }

    public function testARelativeDirectoryIsPinnedAsAnAbsoluteWorkTree(): void
    {
        file_put_contents($this->repository->path.'/README.md', "changed\n");
        chdir(dirname($this->repository->path));
        $relative = basename($this->repository->path);

        $call = new HardenedGit()->call(['status', '--short'], $relative);

        self::assertSame(realpath($this->repository->path), $call->directory);
        self::assertSame($call->directory, $call->environment['GIT_WORK_TREE']);
        self::assertSame(" M README.md\n", $this->runGit($call->command, $call->environment, $call->directory));
    }

    public function testAPromisorRemoteOfTheRepositoryDoesNotFetchAMissingObjectThroughItsCommand(): void
    {
        $marker = $this->repository->path.'/.git/promisor-ran';
        $script = $this->repository->writeHook('promisor', "#!/bin/sh\ntouch ".$marker."\nexit 1\n");
        $this->repository->commitFile('note.txt', "secret\n");
        $blob = $this->repository->objectAt('HEAD:note.txt');
        $this->repository->promiseMissingObjectsFrom($script);
        $this->repository->removeObject($blob);
        $read = ['cat-file', 'blob', $blob];

        $this->start(['git', ...$read], self::UNHARDENED, $this->repository->path);
        self::assertFileExists($marker, 'the command must run without hardening, or this test proves nothing');
        unlink($marker);

        $call = new HardenedGit()->call($read, $this->repository->path);
        $hardened = $this->start($call->command, $call->environment, $call->directory);
        self::assertFileDoesNotExist($marker);
        self::assertFalse($hardened->isSuccessful(), 'a missing object must fail the call');
    }

    public function testTheEnvironmentRemovesInheritedGitVariablesAndIgnoresUserConfig(): void
    {
        $_ENV['GIT_DIR'] = '/elsewhere';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertFalse($environment['GIT_DIR']);
        self::assertSame('1', $environment['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', $environment['GIT_CONFIG_GLOBAL']);
        self::assertSame('/nonexistent', $environment['HOME']);
    }

    public function testTheEnvironmentTurnsReplacementObjectsAndRepositoryAttributesOff(): void
    {
        $_ENV['GIT_NO_REPLACE_OBJECTS'] = '1';
        $_ENV['GIT_ATTR_SOURCE'] = 'HEAD';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertSame('1', $environment['GIT_NO_REPLACE_OBJECTS']);
        self::assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $environment['GIT_ATTR_SOURCE']);
        self::assertSame('1', $environment['GIT_ATTR_NOSYSTEM']);
    }

    public function testASha256RepositoryGetsTheEmptyTreeOfItsObjectFormat(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitFile('README.md', "base\n");
        file_put_contents($this->repository->path.'/README.md', "changed\n");

        $call = new HardenedGit()->call(['diff', '--name-only', 'HEAD'], $this->repository->path);

        self::assertContains('--attr-source=6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321', $call->command);
        self::assertSame('6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321', $call->environment['GIT_ATTR_SOURCE']);
        self::assertSame("README.md\n", $this->runGit($call->command, $call->environment));
    }

    public function testTheEnvironmentPinsTheWorkTreeAndTurnsGraftsOff(): void
    {
        $_ENV['GIT_WORK_TREE'] = '/elsewhere';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertSame($this->repository->path, $environment['GIT_WORK_TREE']);
        self::assertSame('/nonexistent', $environment['GIT_GRAFT_FILE']);
    }

    public function testAGraftFileOfTheRepositoryDoesNotHideACommit(): void
    {
        $base = $this->repository->objectAt('HEAD');
        $this->repository->commitEmpty('Hidden');
        $head = $this->repository->commitEmpty('Head');
        file_put_contents($this->repository->path.'/.git/info/grafts', $head.' '.$base."\n");
        $subjects = ['log', '--format=%s'];

        self::assertSame("Head\nChange README.md\n", $this->runGit(['git', ...$subjects], []), 'the graft must hide the commit without hardening, or this test proves nothing');

        self::assertSame("Head\nHidden\nChange README.md\n", $this->runHardened($subjects));
    }

    public function testAWorkTreeInTheRepositoryConfigDoesNotRedirectWorkingTreeReads(): void
    {
        $elsewhere = $this->repository->path.'/.git/elsewhere';
        mkdir($elsewhere);
        copy($this->repository->path.'/README.md', $elsewhere.'/README.md');
        $this->repository->configure('core.worktree', $elsewhere);
        file_put_contents($this->repository->path.'/README.md', "changed\n");
        $changed = ['diff', '--name-only'];

        self::assertSame('', $this->runGit(['git', ...$changed], []), 'the setting must hide the change without hardening, or this test proves nothing');

        self::assertSame("README.md\n", $this->runHardened($changed));
    }

    public function testAHookOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/hook-ran';
        $this->repository->writeHook('pre-commit', "#!/bin/sh\ntouch ".$marker."\n");

        $this->runGit(['git', ...self::EMPTY_COMMIT], []);
        self::assertFileExists($marker, 'the hook must run without hardening, or this test proves nothing');
        unlink($marker);

        $this->runHardened(self::EMPTY_COMMIT);
        self::assertFileDoesNotExist($marker);
    }

    public function testAnFsmonitorCommandOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/fsmonitor-ran';
        $script = $this->repository->writeHook('fsmonitor-script', "#!/bin/sh\ntouch ".$marker."\n");
        $this->repository->configure('core.fsmonitor', $script);

        $this->runGit(['git', 'status'], []);
        self::assertFileExists($marker, 'the command must run without hardening, or this test proves nothing');
        unlink($marker);

        $this->runHardened(['status']);
        self::assertFileDoesNotExist($marker);
    }

    public function testACleanFilterOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/.git/clean-ran';
        $this->repository->configure('filter.evil.clean', 'touch '.$marker.'; cat');
        $this->repository->commitFile('.gitattributes', "*.txt filter=evil\n");
        $this->repository->commitFile('note.txt', "base\n");
        file_put_contents($this->repository->path.'/note.txt', "changed\n");
        unlink($marker);

        $this->runGit(['git', 'diff', '--name-only', 'HEAD'], []);
        self::assertFileExists($marker, 'the filter must run without hardening, or this test proves nothing');
        unlink($marker);

        self::assertSame("note.txt\n", $this->runHardened(['diff', '--name-only', 'HEAD']));
        self::assertFileDoesNotExist($marker);
    }

    public function testAGpgProgramOfTheRepositoryDoesNotRunToShowACommitSignature(): void
    {
        $marker = $this->repository->path.'/.git/gpg-ran';
        $gpg = $this->repository->writeHook('gpg', "#!/bin/sh\ntouch ".$marker."\n");
        $this->repository->commitWithASignatureHeader();
        $this->repository->configure('log.showSignature', 'true');
        $this->repository->configure('gpg.program', $gpg);

        $this->runGit(['git', ...self::SUBJECT], []);
        self::assertFileExists($marker, 'the program must run without hardening, or this test proves nothing');
        unlink($marker);

        self::assertSame("Signed\n", $this->runHardened(self::SUBJECT));
        self::assertFileDoesNotExist($marker);
    }

    public function testAnOutputEncodingOfTheRepositoryDoesNotRewriteACommitSubject(): void
    {
        $subject = "Subject \u{2014} with a long dash";
        $this->repository->commitEmpty($subject);
        $this->repository->configure('i18n.logOutputEncoding', 'ASCII//TRANSLIT');

        self::assertSame("Subject -- with a long dash\n", $this->runGit(['git', ...self::SUBJECT], []), 'the encoding must rewrite the dash without hardening, or this test proves nothing');

        self::assertSame($subject."\n", $this->runHardened(self::SUBJECT));
    }

    public function testACaseInsensitiveSettingOfTheRepositoryDoesNotHideAFileBesideOneThatDiffersOnlyInCase(): void
    {
        $this->repository->commitFile('safe.php', "<?php\n");
        file_put_contents($this->repository->path.'/SAFE.php', "<?php\n");
        $this->repository->configure('core.ignoreCase', 'true');
        $untracked = ['ls-files', '--others'];

        self::assertSame('', $this->runGit(['git', ...$untracked], []), 'the setting must hide the file without hardening, or this test proves nothing');

        self::assertSame("SAFE.php\n", $this->runHardened($untracked));
    }

    public function testARepositoryWhoseInfoAttributesExistIsRefusedBecauseTheyCouldHideAFileAsBinary(): void
    {
        $this->repository->commitFile('note.txt', "secret\n");
        file_put_contents($this->repository->path.'/.git/info/attributes', "*.txt -diff\n");

        self::assertStringContainsString('Binary files', $this->runGit(['git', 'log', '-p', '-1'], []), 'the attribute must hide the file without hardening, or this test proves nothing');

        try {
            new HardenedGit()->environment($this->repository->path);
            self::fail('A repository with info/attributes must be refused.');
        } catch (RepositoryAttributesRefused $refused) {
            self::assertStringContainsString('/.git/info/attributes exists', $refused->getMessage());
        }
    }

    public function testABareCallNamesTheRepositoryAndLeavesTheWorkTreeUnset(): void
    {
        $mirror = $this->mirror();
        $_ENV['GIT_WORK_TREE'] = '/elsewhere';
        $_ENV['GIT_DIR'] = '/elsewhere';

        $call = new HardenedGit()->callInBareRepository(['rev-parse', '--is-bare-repository'], $mirror);

        self::assertSame(
            ['git', '--no-replace-objects', '--no-lazy-fetch', '--literal-pathspecs', '--git-dir='.realpath($mirror), '--bare', '--attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904', ...self::SETTINGS, 'rev-parse', '--is-bare-repository'],
            $call->command,
        );
        self::assertSame(realpath($mirror), $call->directory);
        self::assertFalse($call->environment['GIT_WORK_TREE']);
        self::assertFalse($call->environment['GIT_DIR']);
        self::assertSame('', $call->environment['GIT_ALLOW_PROTOCOL']);
        self::assertSame('/nonexistent', $call->environment['GIT_GRAFT_FILE']);
        self::assertSame("true\n", $this->runGit($call->command, $call->environment, $call->directory));
    }

    public function testABareCallOfASha256RepositoryGetsTheEmptyTreeOfItsObjectFormat(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitFile('README.md', "base\n");

        $call = new HardenedGit()->callInBareRepository(['log', '-1', '--format=%s'], $this->mirror());

        self::assertSame('6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321', $call->environment['GIT_ATTR_SOURCE']);
        self::assertSame("Change README.md\n", $this->runGit($call->command, $call->environment, $call->directory));
    }

    public function testAWorkTreeInTheConfigOfABareRepositoryDoesNotApplyToABareCall(): void
    {
        $mirror = $this->mirror();
        $this->runGit(['git', '--git-dir='.$mirror, 'config', 'core.worktree', $this->decoy()], []);

        $call = new HardenedGit()->callInBareRepository(['rev-parse', '--is-bare-repository'], $mirror);
        self::assertSame("true\n", $this->runGit($call->command, $call->environment, $call->directory));

        $status = new HardenedGit()->callInBareRepository(['status', '--short'], $mirror);
        $process = $this->start($status->command, $status->environment, $status->directory);
        self::assertFalse($process->isSuccessful(), 'a working-tree read must find no work tree');
        self::assertStringNotContainsString('planted.txt', $process->getOutput());
    }

    public function testABareCallRefusesARepositoryWhoseConfigGivesItAWorkTree(): void
    {
        $mirror = $this->mirror();
        $decoy = $this->decoy();
        $this->runGit(['git', '--git-dir='.$mirror, 'config', 'core.worktree', $decoy], []);
        $this->runGit(['git', '--git-dir='.$mirror, 'config', 'core.bare', 'false'], []);

        self::assertSame($decoy."\n", $this->runGit(['git', '--git-dir='.$mirror, '--bare', 'rev-parse', '--show-toplevel'], [], $mirror), 'the setting must beat a plain bare pin, or this test proves nothing');

        $refusal = $this->bareRefusal(RuntimeException::class, $mirror);

        self::assertStringEndsWith('/mirror.git names a work tree in its config (core.worktree), so a bare call refuses it', $refusal);
    }

    public function testABareCallReadsTheGitDirectoryOfACheckoutWithoutItsWorkTree(): void
    {
        file_put_contents($this->repository->path.'/README.md', "changed\n");
        $gitDirectory = $this->repository->path.'/.git';

        $log = new HardenedGit()->callInBareRepository(['log', '-1', '--format=%s'], $gitDirectory);
        self::assertSame("Change README.md\n", $this->runGit($log->command, $log->environment, $log->directory));

        $status = new HardenedGit()->callInBareRepository(['status', '--short'], $gitDirectory);
        self::assertFalse($this->start($status->command, $status->environment, $status->directory)->isSuccessful(), 'a working-tree read must find no work tree');
    }

    public function testABareCallRefusesTheGitDirectoryOfACheckoutWhoseConfigNamesAWorkTree(): void
    {
        $this->repository->configure('core.worktree', $this->decoy());

        $refusal = $this->bareRefusal(RuntimeException::class, $this->repository->path.'/.git');

        self::assertStringEndsWith('/.git names a work tree in its config (core.worktree), so a bare call refuses it', $refusal);
    }

    public function testABareCallRefusesAGitDirectoryWhoseConfigNamesItselfAsTheWorkTree(): void
    {
        $gitDirectory = (string) realpath($this->repository->path.'/.git');
        $this->repository->configure('core.worktree', $gitDirectory);

        self::assertStringContainsString('names a work tree in its config', $this->bareRefusal(RuntimeException::class, $gitDirectory));
    }

    public function testABareCallRefusesARepositoryWhoseInfoAttributesExist(): void
    {
        $mirror = $this->mirror();
        file_put_contents($mirror.'/info/attributes', "*.txt -diff\n");

        self::assertStringContainsString('/info/attributes exists', $this->bareRefusal(RepositoryAttributesRefused::class, $mirror));
    }

    public function testABareCallOfAMissingRepositoryLeavesTheFailureToTheCall(): void
    {
        $missing = $this->repository->path.'/.git/missing.git';

        $call = new HardenedGit()->callInBareRepository(['rev-parse', 'HEAD'], $missing);

        self::assertSame($missing, $call->directory);
        self::assertContains('--git-dir='.$missing, $call->command);
    }

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->workingDirectory = (string) getcwd();
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        chdir($this->workingDirectory);
        $this->repository->remove();
    }

    /**
     * @param class-string<RuntimeException|RepositoryAttributesRefused> $refusal
     */
    private function bareRefusal(string $refusal, string $repository): string
    {
        try {
            new HardenedGit()->callInBareRepository(['rev-parse', 'HEAD'], $repository);
        } catch (RuntimeException|RepositoryAttributesRefused $refused) {
            self::assertInstanceOf($refusal, $refused);

            return $refused->getMessage();
        }

        self::fail('The bare call must be refused.');
    }

    private function mirror(): string
    {
        $mirror = $this->repository->path.'/.git/mirror.git';
        $this->runGit(['git', 'clone', '--mirror', '--quiet', '--', $this->repository->path, $mirror], self::UNHARDENED);

        return $mirror;
    }

    private function decoy(): string
    {
        $decoy = $this->repository->path.'/.git/decoy';
        mkdir($decoy);
        file_put_contents($decoy.'/planted.txt', "planted\n");

        return $decoy;
    }

    /**
     * @param list<string> $arguments
     */
    private function runHardened(array $arguments): string
    {
        $call = new HardenedGit()->call($arguments, $this->repository->path);

        return $this->runGit($call->command, $call->environment);
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    private function runGit(array $command, array $environment, ?string $directory = null): string
    {
        $process = $this->start($command, $environment, $directory ?? $this->repository->path);

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    private function start(array $command, array $environment, string $directory): Process
    {
        $process = new Process($command, $directory, $environment);
        $process->run();

        return $process;
    }
}
