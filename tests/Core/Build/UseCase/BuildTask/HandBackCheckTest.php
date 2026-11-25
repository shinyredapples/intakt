<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackCheck;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\ObjectIdMalformed;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBackChecks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(HandBackCheck::class)]
#[UsesClass(HandBackFile::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(ObjectIdMalformed::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(BuildTaskRequest::class)]
final class HandBackCheckTest extends TestCase
{
    public function testAHeadThatIsNoObjectIdIsTheFailureOfTheStep(): void
    {
        $checks = self::createStub(HandBackChecks::class);
        $checks->method('check')->willReturn(new AcceptedHandBack(null, 'not-an-id', 1));

        self::assertSame('"not-an-id" is no object id', $this->check($checks)->check($this->request(), 'BUNDLE'));
    }

    public function testABareInvalidArgumentExceptionFromTheChecksPassesThrough(): void
    {
        $checks = self::createStub(HandBackChecks::class);
        $checks->method('check')->willThrowException(new InvalidArgumentException('a bug in the checks'));

        $this->expectExceptionObject(new InvalidArgumentException('a bug in the checks'));

        $this->check($checks)->check($this->request(), 'BUNDLE');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(glob(sys_get_temp_dir().'/intakt-hand-back-check-*') ?? []);
    }

    private function check(HandBackChecks $checks): HandBackCheck
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn('/mirror');

        return new HandBackCheck($checkout, $checks, new HandBackFile($checkout, new Filesystem(), sys_get_temp_dir().'/intakt-hand-back-check-'.bin2hex(random_bytes(4))));
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('acme', 7, 'remote', 'task-7', '/clone', 'image');
    }
}
