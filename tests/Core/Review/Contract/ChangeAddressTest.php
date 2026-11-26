<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeAddress::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
final class ChangeAddressTest extends TestCase
{
    public function testAGitHubPullRequestAddressIsLowercased(): void
    {
        self::assertSame('https://github.com/acme/my.widgets/pull/7', ChangeAddress::canonical('HTTPS://GitHub.com/Acme/My.Widgets/pull/7'));
    }

    public function testThePullRequestNumberOfAGitHubPullRequestAddressIsRead(): void
    {
        self::assertSame('7', ChangeAddress::pullRequestNumber('HTTPS://GitHub.com/Acme/Widgets/pull/7/'));
    }

    public function testAnAddressThatIsNotAGitHubPullRequestHasNoPullRequestNumber(): void
    {
        self::assertNull(ChangeAddress::pullRequestNumber('feat/pull/7'));
        self::assertNull(ChangeAddress::pullRequestNumber('https://example.com/acme/widgets/pull/7'));
    }

    public function testAnAddressTheCanonicalFormLeavesAloneHasNoPullRequestNumber(): void
    {
        self::assertNull(ChangeAddress::pullRequestNumber('https://github.com/acme/../pull/12'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalGitHubPullRequestForms(): iterable
    {
        yield 'http scheme' => ['http://github.com/acme/widgets/pull/7'];
        yield 'user info' => ['https://bot@github.com/acme/widgets/pull/7'];
        yield 'port' => ['https://github.com:8443/acme/widgets/pull/7'];
        yield 'ssh scheme' => ['ssh://github.com/acme/widgets/pull/7'];
    }

    #[DataProvider('nonCanonicalGitHubPullRequestForms')]
    public function testAPullRequestPathUnderAnotherSchemeUserInfoOrPortKeepsItsSpellingAndHasNoPullRequestNumber(string $address): void
    {
        self::assertSame($address, ChangeAddress::canonical($address));
        self::assertNull(ChangeAddress::pullRequestNumber($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherFormsOfOnePullRequest(): iterable
    {
        yield 'a fragment' => ['https://github.com/Acme/Widgets/pull/7#discussion'];
        yield 'a query' => ['https://github.com/Acme/Widgets/pull/7?tab=files'];
        yield 'a trailing slash' => ['https://github.com/Acme/Widgets/pull/7/'];
        yield 'a trailing newline' => ["https://github.com/Acme/Widgets/pull/7\n"];
        yield 'surrounding spaces' => ['  https://github.com/acme/widgets/pull/7 '];
    }

    #[DataProvider('otherFormsOfOnePullRequest')]
    public function testAnotherFormTheForgeAcceptsForOnePullRequestHasTheBareLowercaseAddress(string $address): void
    {
        self::assertSame('https://github.com/acme/widgets/pull/7', ChangeAddress::canonical($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPullRequests(): iterable
    {
        yield 'a local branch' => ['feat/Widget'];
        yield 'another host' => ['https://GitLab.com/Acme/Widgets/pull/7'];
        yield 'an issue of the repository' => ['https://github.com/Acme/Widgets/issues/7'];
        yield 'a pull request number with text after it' => ['https://github.com/Acme/Widgets/pull/7/Files'];
        yield 'a repository of only dots' => ['https://github.com/Acme/../pull/7'];
        yield 'a port' => ['https://github.com:443/Acme/Widgets/pull/7'];
        yield 'credentials' => ['https://someone@github.com/Acme/Widgets/pull/7'];
        yield 'plain http' => ['http://github.com/Acme/Widgets/pull/7'];
    }

    #[DataProvider('notPullRequests')]
    public function testAnAddressThatIsNoGitHubPullRequestKeepsItsSpelling(string $address): void
    {
        self::assertSame($address, ChangeAddress::canonical($address));
    }

    #[DataProvider('otherFormsOfOnePullRequest')]
    public function testTheForgeReadsTheSamePullRequestFromAnAcceptedFormAndFromItsCanonicalForm(string $address): void
    {
        $named = PullRequestAddress::of(new ChangeId($address));
        $canonical = PullRequestAddress::of(new ChangeId(ChangeAddress::canonical($address)));

        self::assertSame([strtolower($named->owner), strtolower($named->repository), $named->number], [$canonical->owner, $canonical->repository, $canonical->number]);
    }

    #[DataProvider('notPullRequests')]
    public function testTheForgeRefusesEveryAddressThatKeepsItsSpelling(string $address): void
    {
        $this->expectException(ChangeNotFound::class);

        PullRequestAddress::of(new ChangeId($address));
    }

    public function testTwoSpellingsOfOnePullRequestAreTheSameChangeAndTwoSpellingsOfABranchAreNot(): void
    {
        self::assertSame(
            [true, false],
            [ChangeAddress::sameChange('https://github.com/Acme/Widgets/pull/7', 'https://github.com/acme/widgets/pull/7'), ChangeAddress::sameChange('feat/Widget', 'feat/widget')],
        );
    }
}
