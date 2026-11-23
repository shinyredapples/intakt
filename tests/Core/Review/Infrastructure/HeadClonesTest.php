<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Infrastructure\HeadClones;
use Intakt\Plugin\Forge\Contract\CommitId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// Real git on a fixture repository, because what is under test is what git writes into the clone.
#[CoversClass(HeadClones::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class HeadClonesTest extends TestCase
{
    // Because CI runs as a user without a git identity, and git refuses a commit without one.
    private const array IDENTITY = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false'];

    // A setting given to the process, as an outer git or a tool may export it.
    private const array PROCESS_SETTING = ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'core.autocrlf', 'GIT_CONFIG_VALUE_0' => 'true'];

    private string $root = '';

    private Filesystem $filesystem;

    public function testAReviewCanCloneASha256Head(): void
    {
        $head = $this->committed("committed\n", '--object-format=sha256');

        $clone = new HeadClones($this->filesystem, $this->root.'/clones')->cloneAt($this->root.'/source', $head);

        self::assertSame("committed\n", file_get_contents($clone.'/example.txt'));
    }

    public function testTheHostsGitSettingsDoNotRewriteTheCommittedBytes(): void
    {
        $head = $this->committed("one\ntwo\n");
        $config = $this->root.'/gitconfig';
        $this->filesystem->dumpFile($config, "[core]\n\tautocrlf = true\n");
        putenv('GIT_CONFIG_GLOBAL='.$config);
        $_SERVER['GIT_CONFIG_GLOBAL'] = $config;
        $_ENV['GIT_CONFIG_GLOBAL'] = $config;

        $clone = new HeadClones($this->filesystem, $this->root.'/clones')->cloneAt($this->root.'/source', $head);

        self::assertSame("one\ntwo\n", file_get_contents($clone.'/example.txt'));
    }

    public function testTheHeadsAttributesDoNotRewriteTheCommittedBytes(): void
    {
        $this->filesystem->dumpFile($this->root.'/source/.gitattributes', "example.txt text eol=crlf ident\n");
        $head = $this->committed("one\n\$Id\$\n");

        $clone = new HeadClones($this->filesystem, $this->root.'/clones')->cloneAt($this->root.'/source', $head);

        self::assertSame("one\n\$Id\$\n", file_get_contents($clone.'/example.txt'));
    }

    public function testAGitSettingOfTheProcessDoesNotRewriteTheCommittedBytes(): void
    {
        $head = $this->committed("one\ntwo\n");
        $this->export(self::PROCESS_SETTING);

        $clone = new HeadClones($this->filesystem, $this->root.'/clones')->cloneAt($this->root.'/source', $head);

        self::assertSame("one\ntwo\n", file_get_contents($clone.'/example.txt'));
    }

    public function testAFailedCloneLeavesNoCheckoutAndKeepsTheOtherJobs(): void
    {
        $head = $this->committed("one\ntwo\n");
        $clones = new HeadClones($this->filesystem, $this->root.'/clones');
        $first = $clones->cloneAt($this->root.'/source', $head);

        try {
            $clones->cloneAt($this->root.'/source', new CommitId(str_repeat('1', strlen($head->hash))));
            self::fail('A head the source does not hold was cloned.');
        } catch (ProcessFailedException) {
            self::assertSame([$first], glob($this->root.'/clones/head-*'));
        }
    }

    public function testTwoJobsGetTwoCheckoutsAndRemovingOneLeavesTheOther(): void
    {
        $head = $this->committed("one\ntwo\n");
        $clones = new HeadClones($this->filesystem, $this->root.'/clones');

        $first = $clones->cloneAt($this->root.'/source', $head);
        $second = $clones->cloneAt($this->root.'/source', $head);
        $clones->remove($first);

        self::assertNotSame($first, $second);
        self::assertDirectoryDoesNotExist($first);
        self::assertSame("one\ntwo\n", file_get_contents($second.'/example.txt'));
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir().'/intakt-head-clones-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root.'/source');
    }

    protected function tearDown(): void
    {
        foreach (['GIT_CONFIG_GLOBAL', ...array_keys(self::PROCESS_SETTING)] as $name) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $this->filesystem->remove($this->root);
    }

    private function committed(string $content, string ...$init): CommitId
    {
        $this->git('init', '--quiet', ...$init);
        $this->filesystem->dumpFile($this->root.'/source/example.txt', $content);
        $this->git('add', '.');
        $this->git(...[...self::IDENTITY, 'commit', '--quiet', '-m', 'Fixture']);

        return new CommitId($this->git('rev-parse', 'HEAD'));
    }

    /**
     * @param array<string, string> $variables
     */
    private function export(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
    }

    private function git(string ...$arguments): string
    {
        return trim(new Process(['git', '-C', $this->root.'/source', ...$arguments], null, ['GIT_CONFIG_GLOBAL' => '/dev/null'])->mustRun()->getOutput());
    }
}
