<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[CoversClass(PrePushCheck::class)]
#[UsesClass(HandBackFile::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackBranchRefused::class)]
#[UsesClass(JobCredentials::class)]
final class PrePushCheckTest extends TestCase
{
    private string $folder = '';

    public function testARefusedBranchBecomesTheFailureText(): void
    {
        $failure = $this->checkThrowing(HandBackBranchRefused::isMain('main'))->failureOf($this->request(), 'BUNDLE');

        self::assertSame('the task\'s branch "main" is main itself', $failure);
    }

    public function testAnUnexpectedInvalidArgumentExceptionFromTheCheckJobPassesThrough(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('a bug in the check job'));

        $this->checkThrowing(new InvalidArgumentException('a bug in the check job'))->failureOf($this->request(), 'BUNDLE');
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-pre-push-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function checkThrowing(Throwable $failure): PrePushCheck
    {
        $jobs = self::createStub(CheckJobs::class);
        $jobs->method('run')->willThrowException($failure);
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->folder.'/mirror');

        return new PrePushCheck($jobs, new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs'));
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', 7, $this->folder.'/remote', 'main', $this->folder.'/clone', 'intakt-job-alpha:1');
    }
}
