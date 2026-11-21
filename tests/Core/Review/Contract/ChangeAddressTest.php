<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ChangeAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeAddress::class)]
final class ChangeAddressTest extends TestCase
{
    public function testAGitHubPullRequestAddressIsLowercased(): void
    {
        self::assertSame('https://github.com/acme/my.widgets/pull/7', ChangeAddress::canonical('HTTPS://GitHub.com/Acme/My.Widgets/pull/7'));
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
    }

    #[DataProvider('notPullRequests')]
    public function testAnAddressThatIsNoGitHubPullRequestKeepsItsSpelling(string $address): void
    {
        self::assertSame($address, ChangeAddress::canonical($address));
    }

    public function testTwoSpellingsOfOnePullRequestAreTheSameChangeAndTwoSpellingsOfABranchAreNot(): void
    {
        self::assertSame(
            [true, false],
            [ChangeAddress::sameChange('https://github.com/Acme/Widgets/pull/7', 'https://github.com/acme/widgets/pull/7'), ChangeAddress::sameChange('feat/Widget', 'feat/widget')],
        );
    }
}
