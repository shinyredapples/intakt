<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteCheck;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushRemoteCheck::class)]
#[UsesClass(BuildTaskRequest::class)]
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
        yield 'a dot segment' => ['https://github.com/acme/./alpha'];
        yield 'a percent-encoded letter' => ['https://github.com/acme/alph%61'];
        yield 'a doubled leading slash' => ['https://github.com//acme/alpha'];
    }

    #[DataProvider('remotesOutsideTheConfiguredRepository')]
    public function testARemoteOutsideTheConfiguredRepositoryIsRefusedNamingTheConfiguredOne(string $remote): void
    {
        $failure = $this->check('acme/alpha')->failureOf($this->request($remote));

        self::assertNotNull($failure);
        self::assertStringContainsString('"acme/alpha"', $failure);
    }

    public function testTheRefusalNamesTheRemoteItRefuses(): void
    {
        self::assertSame('the remote "https://github.com/acme/beta.git" is not the configured GitHub repository "acme/alpha" of project "alpha"; pass its https://github.com/acme/alpha URL without credentials', $this->check('acme/alpha')->failureOf($this->request('https://github.com/acme/beta.git')));
    }

    public function testTheRefusalShowsTheRemoteWithoutItsCredentialsQueryOrFragment(): void
    {
        $failure = (string) $this->check('acme/alpha')->failureOf($this->request('https://x-access-token:s3cret@github.com/acme/alpha?token=t0ken#k3y'));

        self::assertStringContainsString('the remote "https://github.com/acme/alpha" (credentials, query or fragment not shown)', $failure);
        self::assertStringNotContainsString('s3cret', $failure);
        self::assertStringNotContainsString('t0ken', $failure);
        self::assertStringNotContainsString('k3y', $failure);
    }

    #[DataProvider('remotesWithoutAHostThatMayHoldCredentials')]
    public function testTheRefusalDoesNotShowARemoteWithoutAHostThatMayHoldCredentials(string $remote): void
    {
        $failure = (string) $this->check('acme/alpha')->failureOf($this->request($remote));

        self::assertStringContainsString('the remote (not shown: it may hold credentials)', $failure);
        self::assertStringNotContainsString('s3cret', $failure);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function remotesWithoutAHostThatMayHoldCredentials(): iterable
    {
        yield 'an ssh address with a token' => ['user:s3cret@github.com:acme/alpha.git'];
        yield 'an ssh address with a user' => ['s3cret@github.com:acme/alpha.git'];
        yield 'no URI' => ['s3cret@github.com:acme/alpha.git:%zz'];
    }

    public function testTheRefusalShowsALocalPathAsItIs(): void
    {
        self::assertStringContainsString('the remote "/remotes/alpha.git" is not', (string) $this->check('acme/alpha')->failureOf($this->request('/remotes/alpha.git')));
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
