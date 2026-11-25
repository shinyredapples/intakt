<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\PullRequestTextCheck;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Contract\PullRequestTextTaskRefused;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestTextCheck::class)]
#[UsesClass(PullRequestTextTask::class)]
#[UsesClass(PullRequestTextTaskRefused::class)]
#[UsesClass(BuildTaskRequest::class)]
final class PullRequestTextCheckTest extends TestCase
{
    public function testARepositoryThatCannotBeFoundIsTheFailureOfTheStep(): void
    {
        $failure = $this->check('/nonexistent/mirror', $this->createStub(PullRequestTextRound::class))->failureOf($this->request(), 'task', 'body');

        self::assertSame('the repository "/nonexistent/mirror" cannot be found', $failure);
    }

    public function testABareInvalidArgumentExceptionFromTheRoundPassesThrough(): void
    {
        $round = $this->createStub(PullRequestTextRound::class);
        $round->method('route')->willThrowException(new InvalidArgumentException('a bug in the round'));

        $this->expectExceptionObject(new InvalidArgumentException('a bug in the round'));

        $this->check(__DIR__, $round)->failureOf($this->request(), 'task', 'body');
    }

    private function check(string $mirror, PullRequestTextRound $round): PullRequestTextCheck
    {
        $checkout = $this->createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($mirror);

        return new PullRequestTextCheck($checkout, $round);
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('acme', 7, 'remote', 'task-7', '/clone', 'image');
    }
}
