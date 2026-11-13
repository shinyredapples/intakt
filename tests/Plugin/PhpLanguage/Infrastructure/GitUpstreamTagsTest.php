<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTagNotRead;
use Intakt\Plugin\PhpLanguage\Infrastructure\GitUpstreamTags;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// A local repository over file:// stands in for GitHub, because no test may reach the network and the network call allows file besides https.
#[CoversClass(GitUpstreamTags::class)]
#[UsesClass(UpstreamTagNotRead::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(NetworkGitFailed::class)]
final class GitUpstreamTagsTest extends TestCase
{
    private TemporaryRepository $upstream;

    protected function setUp(): void
    {
        $this->upstream = new TemporaryRepository();
    }

    protected function tearDown(): void
    {
        $this->upstream->remove();
    }

    public function testALightweightTagNamesItsCommit(): void
    {
        $commit = $this->upstream->commitFile('src/Uid.php', 'one');
        $this->upstream->tag('v8.0.0');

        self::assertSame($commit, $this->tags()->commitOf($this->url(), 'v8.0.0'));
    }

    public function testAnAnnotatedTagNamesTheCommitItPointsTo(): void
    {
        $commit = $this->upstream->commitFile('src/Uid.php', 'one');
        $this->upstream->annotatedTag('v8.0.0', $commit);

        self::assertSame($commit, $this->tags()->commitOf($this->url(), 'v8.0.0'));
    }

    public function testATagTheRepositoryLacksNamesNoCommit(): void
    {
        $this->upstream->commitFile('src/Uid.php', 'one');
        $this->upstream->tag('v8.0.0');

        self::assertNull($this->tags()->commitOf($this->url(), 'v8.0.1'));
    }

    public function testATagThatOnlyEndsLikeTheOneAskedForDoesNotCount(): void
    {
        $this->upstream->commitFile('src/Uid.php', 'one');
        $this->upstream->tag('fork/refs/tags/v8.0.0');

        self::assertNull($this->tags()->commitOf($this->url(), 'v8.0.0'));
    }

    public function testARepositoryThatCannotBeReadFailsWithGitsMessageAfterTheRetries(): void
    {
        $clock = new RecordingClock();

        try {
            $_ = new GitUpstreamTags(new HardenedGit(), new NetworkGit($clock, new NullLogger()))->commitOf('file://'.$this->upstream->path.'/missing', 'v8.0.0');
            self::fail('A repository that cannot be read must not name a commit.');
        } catch (UpstreamTagNotRead $unread) {
            self::assertStringStartsWith('the upstream tag could not be read: ', $unread->getMessage());
            self::assertSame([10, 30], $clock->sleeps);
        }
    }

    private function tags(): GitUpstreamTags
    {
        return new GitUpstreamTags(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()));
    }

    private function url(): string
    {
        return 'file://'.$this->upstream->path;
    }
}
