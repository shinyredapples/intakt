<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusReads::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RoundStatusLabel::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(TrustAnswers::class)]
final class GitHubStatusReadsTest extends TestCase
{
    public function testAnEventOfAnotherLabelFamilyWithoutAnActorIsIgnored(): void
    {
        $events = $this->readerOfEvents(static fn (int $page): string => 1 === $page ? '[{"event":"labeled","label":{"name":"area:docs"},"actor":null}]' : '[]')->of($this->address());

        self::assertSame([], $events);
    }

    public function testAStatusEventWithoutAnActorFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => '[{"event":"labeled","label":{"name":"status:in-review"},"actor":null}]')->of($this->address());
    }

    public function testAHistoryOfTenFullPagesOfUnrelatedEventsIsRead(): void
    {
        $events = $this->readerOfEvents(static fn (int $page): string => json_encode($page <= 10 ? array_fill(0, 100, ['event' => 'referenced']) : [], JSON_THROW_ON_ERROR))->of($this->address());

        self::assertSame([], $events);
    }

    public function testAHistoryOfMoreThanTenFullPagesFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => json_encode(array_fill(0, 100, ['event' => 'referenced']), JSON_THROW_ON_ERROR))->of($this->address());
    }

    public function testAnEventListingThatIsAnObjectFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => '{}')->of($this->address());
    }

    public function testAnEventEntryThatIsAListFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => '[["referenced"]]')->of($this->address());
    }

    public function testAnEventEntryThatIsAListOnThePageAfterAFullOneFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => 1 === $page ? json_encode(array_fill(0, 100, ['event' => 'referenced']), JSON_THROW_ON_ERROR) : '[["referenced"]]')->of($this->address());
    }

    #[DataProvider('malformedEventListings')]
    public function testAMalformedEventListingFailsTheRead(string $payload): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfEvents(static fn (int $page): string => $payload)->of($this->address());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEventListings(): iterable
    {
        yield 'an object with numeric keys' => ['{"0":{"event":"referenced"}}'];
        yield 'an entry that is an empty array' => ['[[]]'];
        yield 'an entry that is an empty object' => ['[{}]'];
        yield 'an entry that is a string' => ['["referenced"]'];
        yield 'a label event with an empty label name' => ['[{"event":"labeled","label":{"name":""},"actor":null}]'];
    }

    public function testTheLabelsOfAChangeAreReadByName(): void
    {
        $labels = $this->readerOfLabels('[{"name":"status:in-review"},{"name":"area:docs"}]')->labelsOn($this->address());

        self::assertSame(['status:in-review', 'area:docs'], $labels);
    }

    public function testALabelEntryThatIsAListFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->readerOfLabels('[["status:approved"]]')->labelsOn($this->address());
    }

    /**
     * @param Closure(int): string $body
     */
    private function readerOfEvents(Closure $body): GitHubStatusReads
    {
        return $this->readerOf('/events', $body);
    }

    private function address(): PullRequestAddress
    {
        return PullRequestAddress::of(new ChangeId(RecordedTrust::PULL_BY_WRITER));
    }

    private function readerOfLabels(string $firstPage): GitHubStatusReads
    {
        return $this->readerOf('/labels', static fn (int $page): string => 1 === $page ? $firstPage : '[]');
    }

    /**
     * @param Closure(int): string $body
     */
    private function readerOf(string $listing, Closure $body): GitHubStatusReads
    {
        $trust = new RecordedTrust()->client();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($trust, $listing, $body): ResponseInterface {
            if (!str_ends_with((string) parse_url($url, PHP_URL_PATH), $listing)) {
                return $trust->request($method, $url, $options);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return new MockResponse($body((int) $query['page']));
        }, 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);

        return new GitHubStatusReads($wiring->calls, $wiring->tokens);
    }
}
