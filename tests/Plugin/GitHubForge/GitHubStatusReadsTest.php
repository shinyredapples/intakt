<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusReads::class)]
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

    private function address(): PullRequestAddress
    {
        return PullRequestAddress::of(new ChangeId(RecordedTrust::PULL_BY_WRITER));
    }

    /**
     * @param Closure(int): string $body
     */
    private function readerOfEvents(Closure $body): GitHubStatusReads
    {
        $trust = new RecordedTrust()->client();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($trust, $body): ResponseInterface {
            if (!str_ends_with((string) parse_url($url, PHP_URL_PATH), '/events')) {
                return $trust->request($method, $url, $options);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return new MockResponse($body((int) $query['page']));
        }, 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);

        return new GitHubStatusReads($wiring->calls, $wiring->tokens);
    }
}
