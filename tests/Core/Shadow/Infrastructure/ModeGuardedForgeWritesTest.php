<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Shadow\Infrastructure\ModeGuardedForgeWrites;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Tests\Core\Shadow\ReviewToPost;
use Intakt\Tests\Core\Shadow\WriteRefusingForge;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModeGuardedForgeWrites::class)]
#[UsesClass(ConfiguredProjectModes::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(ReviewComment::class)]
#[UsesClass(ReviewToken::class)]
final class ModeGuardedForgeWritesTest extends TestCase
{
    public function testAShadowProjectsReviewNeverReachesTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ModeGuardedForgeWrites($forge, new ConfiguredProjectModes(['alpha' => 'shadow']));

        $guard->postReview('alpha', ReviewToPost::change('feature'), ReviewToPost::comment('approve'));

        self::assertSame([], $forge->writeLog);
    }

    public function testAReviewerForLoopProjectsReviewNeverReachesTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ModeGuardedForgeWrites($forge, new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));

        $guard->postReview('alpha', ReviewToPost::change('feature'), ReviewToPost::comment('approve'));

        self::assertSame([], $forge->writeLog);
    }

    public function testAProjectWithoutAModeWritesToTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ModeGuardedForgeWrites($forge, new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));

        $this->expectException(LogicException::class);

        $guard->postReview('beta', ReviewToPost::change('feature'), ReviewToPost::comment('approve'));
    }

    public function testAProjectWithoutAModeWritesToTheForgeBesideAShadowProject(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ModeGuardedForgeWrites($forge, new ConfiguredProjectModes(['alpha' => 'shadow']));

        $this->expectException(LogicException::class);

        $guard->postReview('beta', ReviewToPost::change('feature'), ReviewToPost::comment('approve'));
    }

    public function testALiveProjectWritesToTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ModeGuardedForgeWrites($forge, new ConfiguredProjectModes(['alpha' => 'live']));

        $this->expectExceptionObject(new LogicException('The test forge refuses every write.'));

        $guard->postReview('alpha', ReviewToPost::change('feature'), ReviewToPost::comment('approve'));
    }
}
