<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusReads::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(LabelEvent::class)]
#[UsesClass(TrustWiring::class)]
final class GitHubStatusReadsTest extends TestCase
{
    public function testAnEventsListingWithAnEntryThatIsAListFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->reads('{}', '[["referenced"]]')->of($this->address());
    }

    public function testALabelsListingWithAnEntryThatIsAListFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->reads('[["status:in-review"]]', '[]')->labelsOn($this->address());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function entriesThatAreNoObjects(): iterable
    {
        yield 'an empty object' => ['[{}]'];
        yield 'an empty list' => ['[[]]'];
        yield 'a string' => ['["status:in-review"]'];
        yield 'null' => ['[null]'];
        yield 'an empty object instead of a list' => ['{}'];
        yield 'an object with the key 0 instead of a list' => ['{"0":{"name":"status:in-review","event":"labeled"}}'];
    }

    #[DataProvider('entriesThatAreNoObjects')]
    public function testAnEntryThatIsNoObjectFailsTheReadOfLabelsAndEvents(string $listing): void
    {
        $failed = 0;
        foreach ([fn (): array => $this->reads($listing, '[]')->labelsOn($this->address()), fn (): array => $this->reads('[]', $listing)->of($this->address())] as $read) {
            try {
                $read();
            } catch (ForgeReadFailed) {
                ++$failed;
            }
        }

        self::assertSame(2, $failed);
    }

    public function testAnEventsListingOfObjectsIsReadAndKeepsOnlyStatusLabelEvents(): void
    {
        $events = '[{"event":"referenced"},{"event":"labeled","label":{"name":"area:x"},"actor":{"login":"ann"}},{"event":"unlabeled","label":{"name":"status:in-review"},"actor":{"login":"ann"}}]';

        $read = $this->reads('[]', $events)->of($this->address());

        self::assertEquals([new LabelEvent('status:in-review', 'ann', false)], $read);
    }

    public function testALabelsListingThatEndsAfterAFullTenthPageIsReadWithOneMoreRequest(): void
    {
        $requested = [];

        $labels = $this->paged(0, $requested)->labelsOn($this->address());

        self::assertSame([1000, 11], [count($labels), count($requested)]);
    }

    public function testALabelsListingThatGoesOnAfterAFullTenthPageFailsTheRead(): void
    {
        $requested = [];

        $this->expectException(ForgeReadFailed::class);

        $this->paged(1, $requested)->labelsOn($this->address());
    }

    public function testAnEventsListingThatGoesOnAfterAFullTenthPageFailsTheRead(): void
    {
        $requested = [];

        $this->expectException(ForgeReadFailed::class);

        $this->paged(1, $requested)->of($this->address());
    }

    private function reads(string $labels, string $events): GitHubStatusReads
    {
        $identity = new RecordedTrust(TrustChecks::clock())->client();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($labels, $events, $identity): ResponseInterface {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (1 === preg_match('#/issues/[0-9]+/labels$#', $path)) {
                return new MockResponse($labels);
            }
            if (1 === preg_match('#/issues/[0-9]+/events$#', $path)) {
                return new MockResponse($events);
            }

            return $identity->request($method, $url, $options);
        }, 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);

        return new GitHubStatusReads($wiring->calls, $wiring->tokens);
    }

    private function address(): PullRequestAddress
    {
        return PullRequestAddress::of(new ChangeId(RecordedTrust::PULL_BY_WRITER));
    }

    /**
     * @param list<int> $requested
     */
    private function paged(int $beyond, array &$requested): GitHubStatusReads
    {
        $identity = new RecordedTrust(TrustChecks::clock())->client();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($beyond, &$requested, $identity): ResponseInterface {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (1 !== preg_match('#/issues/[0-9]+/(labels|events)$#', $path)) {
                return $identity->request($method, $url, $options);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = (int) $query['page'];
            $requested[] = $page;
            $count = $page <= 10 ? 100 : $beyond;

            return new MockResponse(json_encode(array_fill(0, $count, ['name' => 'area:x']), JSON_THROW_ON_ERROR));
        }, 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);

        return new GitHubStatusReads($wiring->calls, $wiring->tokens);
    }
}
