<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteCheck;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushRemoteCheck::class)]
#[CoversClass(BuildTaskRequest::class)]
final class PushRemoteCheckTest extends TestCase
{
    public function testALocalPathRemoteIsAllowedWhenTheProjectHasNoGithubEntry(): void
    {
        self::assertNull($this->check(null)->failureOf($this->request('/remotes/alpha.git')));
        self::assertNull($this->check(null)->failureOf($this->request('file:///remotes/alpha.git')));
    }

    #[DataProvider('spellingsOfTheConfiguredRepository')]
    public function testTheConfiguredRepositoryIsAcceptedInAnotherSpelling(string $remote): void
    {
        self::assertNull($this->check('acme/alpha')->failureOf($this->request($remote)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfTheConfiguredRepository(): iterable
    {
        yield 'plain' => ['https://github.com/acme/alpha'];
        yield 'git suffix' => ['https://github.com/acme/alpha.git'];
        yield 'letter case' => ['HTTPS://GitHub.com/ACME/Alpha'];
        yield 'trailing slash' => ['https://github.com/acme/alpha/'];
    }

    #[DataProvider('remotesOutsideTheConfiguredRepository')]
    public function testARemoteOutsideTheConfiguredRepositoryIsRefusedNamingBoth(string $remote): void
    {
        $failure = $this->check('acme/alpha')->failureOf($this->request($remote));

        self::assertNotNull($failure);
        self::assertStringContainsString('"'.$remote.'"', $failure);
        self::assertStringContainsString('"acme/alpha"', $failure);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function remotesOutsideTheConfiguredRepository(): iterable
    {
        yield 'another repository' => ['https://github.com/acme/beta'];
        yield 'another owner' => ['https://github.com/other/alpha.git'];
        yield 'a longer name' => ['https://github.com/acme/alpha-two'];
        yield 'another host' => ['https://example.com/acme/alpha'];
        yield 'a file URL' => ['file:///remotes/alpha.git'];
        yield 'a path' => ['/remotes/alpha.git'];
        yield 'ssh' => ['git@github.com:acme/alpha.git'];
        yield 'a deeper path' => ['https://github.com/acme/alpha/tree/main'];
        yield 'credentials' => ['https://user@github.com/acme/alpha'];
        yield 'no repository' => ['https://github.com/acme'];
        yield 'empty' => [''];
    }

    private function check(?string $configured): PushRemoteCheck
    {
        $repositories = self::createStub(ConfiguredRepositories::class);
        $repositories->method('nameOf')->willReturn($configured);

        return new PushRemoteCheck($repositories);
    }

    private function request(string $remote): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', 7, $remote, 'feat/task', 'clone', 'image');
    }
}
