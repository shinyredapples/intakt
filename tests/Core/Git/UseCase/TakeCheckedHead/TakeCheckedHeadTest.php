<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\TakeCheckedHead;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\TakeCheckedHead\CheckedHeadNotTaken;
use Intakt\Core\Git\UseCase\TakeCheckedHead\TakeCheckedHead;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(TakeCheckedHead::class)]
#[CoversClass(CheckedHeadNotTaken::class)]
#[CoversClass(BranchHead::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class TakeCheckedHeadTest extends TestCase
{
    private TemporaryRepository $source;

    private Mirrors $mirrors;

    private string $clone;

    public function testTheCloneTakesTheCheckedHeadOnItsBranchWithItsFilesCheckedOut(): void
    {
        $head = $this->headInTheMirrorOnly();

        $this->take($head);

        self::assertSame($head, trim($this->git(['rev-parse', 'refs/heads/feat/task'], $this->clone)));
        self::assertSame("refs/heads/feat/task\n", $this->git(['symbolic-ref', 'HEAD'], $this->clone));
        self::assertStringEqualsFile($this->clone.'/b.txt', 'two');
    }

    public function testAHeadThatIsNoObjectIdIsRefusedBeforeGitRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->take('--upload-pack=touch');
    }

    public function testAHeadWithAnUpperCaseObjectIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->take(strtoupper($this->headInTheMirrorOnly()));
    }

    public function testAHeadTheMirrorDoesNotHoldIsNotTaken(): void
    {
        $this->expectException(CheckedHeadNotTaken::class);

        $this->take(str_repeat('0', 40));
    }

    protected function setUp(): void
    {
        $this->source = new TemporaryRepository();
        $this->source->commitFile('a.txt', 'one');
        $this->mirrors = new Mirrors(sys_get_temp_dir().'/intakt-take-head-'.bin2hex(random_bytes(6)));
        $this->clone = $this->mirrors->directory.'-clone';
        $this->git(['clone', '--quiet', '--bare', $this->source->path, $this->mirrors->pathOf('alpha')], $this->source->path);
        $this->git(['clone', '--quiet', $this->source->path, $this->clone], $this->source->path);
        $this->git(['switch', '--quiet', '-c', 'feat/task'], $this->clone);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors->directory, $this->clone]);
        $this->source->remove();
    }

    private function headInTheMirrorOnly(): string
    {
        $head = $this->source->commitFile('b.txt', 'two');
        // Objects only and no ref, because the hand-back check leaves them so in the mirror.
        $this->git(['--git-dir='.$this->mirrors->pathOf('alpha'), 'fetch', '--quiet', $this->source->path, $head], $this->source->path);

        return $head;
    }

    private function take(string $head): void
    {
        new TakeCheckedHead(new HardenedGit(), $this->mirrors)->take('alpha', new BranchHead('feat/task', $head), $this->clone);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->mustRun();

        return $process->getOutput();
    }
}
