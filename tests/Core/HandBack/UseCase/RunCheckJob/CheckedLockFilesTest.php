<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFiles;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckedLockFiles::class)]
#[UsesClass(CheckedLockFile::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(HardenedGit::class)]
final class CheckedLockFilesTest extends TestCase
{
    private const array PATHS = ['composer.json', 'composer.lock'];

    private BuildHistory $history;

    public function testABranchOnMainIsKeyedByTheHeadsLockFiles(): void
    {
        $this->givenMainHolds("{\"packages\": []}\n");
        $head = $this->history->commitFile('composer.lock', "{\"packages\": [\"psr/log\"]}\n", 'Require a package');

        self::assertSame(['composer.json' => "{}\n", 'composer.lock' => "{\"packages\": [\"psr/log\"]}\n"], $this->bytesOf($head));
    }

    public function testTheLockFilesCarryTheBlobsOfTheHead(): void
    {
        $this->givenMainHolds("{\"packages\": []}\n");
        $head = $this->history->commit('Change nothing locked');

        $files = $this->lockFilesOf($head);

        self::assertNotNull($files);
        self::assertSame(trim($this->history->inMirror('rev-parse', $head.':composer.lock')), $files['composer.lock']->blob);
    }

    public function testABranchBehindMainWhoseLockFilesMatchMainsIsKeyedByTheHeadsLockFiles(): void
    {
        $this->givenMainHolds("{\"packages\": []}\n");
        $head = $this->history->commit('Work on the task');
        $this->givenMainMovesOn('README.md', "Main moved on\n");

        self::assertSame(['composer.json' => "{}\n", 'composer.lock' => "{\"packages\": []}\n"], $this->bytesOf($head));
    }

    public function testABranchBehindMainWhoseLockFileDiffersFromMainsGetsNoReuse(): void
    {
        $this->givenMainHolds("{\"packages\": []}\n");
        $head = $this->history->commit('Work on the task');
        $this->givenMainMovesOn('composer.lock', "{\"packages\": [\"psr/log\"]}\n");

        self::assertNull($this->lockFilesOf($head));
    }

    public function testABranchBehindMainThatChangedItsOwnLockFileGetsNoReuse(): void
    {
        $this->givenMainHolds("{\"packages\": []}\n");
        $head = $this->history->commitFile('composer.lock', "{\"packages\": [\"psr/log\"]}\n", 'Require a package');
        $this->givenMainMovesOn('README.md', "Main moved on\n");

        self::assertNull($this->lockFilesOf($head));
    }

    public function testAMissingLockFileGetsNoReuse(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $head = $this->history->commit('Work on the task');

        self::assertNull($this->lockFilesOf($head));
    }

    public function testALockFileThatIsALinkGetsNoReuse(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest');
        $this->history->commitLink('composer.lock', '/etc/hostname', 'Link the lock file');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $head = $this->history->commit('Work on the task');

        self::assertNull($this->lockFilesOf($head));
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function givenMainHolds(string $lock): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest');
        $this->history->commitFile('composer.lock', $lock, 'Add the lock file');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
    }

    private function givenMainMovesOn(string $path, string $content): void
    {
        $this->history->push('task');
        $this->history->checkout('main');
        $this->history->commitFile($path, $content, 'Move main on');
        $this->history->push('main');
        $this->history->checkout('task');
    }

    /**
     * @return ?array<string, string>
     */
    private function bytesOf(string $head): ?array
    {
        $files = $this->lockFilesOf($head);

        return null === $files ? null : array_map(static fn (CheckedLockFile $file): string => $file->bytes, $files);
    }

    /**
     * @return ?non-empty-array<string, CheckedLockFile>
     */
    private function lockFilesOf(string $head): ?array
    {
        // In the mirror, because the check reads the head there once the hand-back is fetched.
        $this->history->push('task');

        return new CheckedLockFiles(new RepositoryGit(new HardenedGit()))->of($this->history->mirror, $head, self::PATHS);
    }
}
