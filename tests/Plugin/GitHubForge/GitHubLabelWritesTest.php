<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubLabelWrites::class)]
final class GitHubLabelWritesTest extends TestCase
{
    /** @var list<string> */
    private array $writes = [];

    public function testARemovalDeletesTheLabelByItsEncodedName(): void
    {
        $this->writesAnswering(200)->remove('/repos/acme/widget/issues/7', 'model:light');

        self::assertSame(['DELETE /repos/acme/widget/issues/7/labels/model%3Alight'], $this->writes);
    }

    public function testALabelAlreadyGoneCountsAsRemoved(): void
    {
        $this->writesAnswering(404)->remove('/repos/acme/widget/issues/7', 'model:light');

        self::assertCount(1, $this->writes);
    }

    public function testARefusedRemovalFailsTheWrite(): void
    {
        $this->expectException(ForgeWriteFailed::class);

        $this->writesAnswering(403)->remove('/repos/acme/widget/issues/7', 'model:light');
    }

    public function testAnAdditionPostsTheLabelByName(): void
    {
        $this->writesAnswering(200)->add('/repos/acme/widget/issues/7', 'status:in-review');

        self::assertSame(['POST /repos/acme/widget/issues/7/labels {"labels":["status:in-review"]}'], $this->writes);
    }

    public function testAnAdditionTheForgeDoesNotFindFailsTheWrite(): void
    {
        $this->expectException(ForgeWriteFailed::class);

        $this->writesAnswering(404)->add('/repos/acme/widget/issues/7', 'status:in-review');
    }

    private function writesAnswering(int $status): GitHubLabelWrites
    {
        $identity = new RecordedTrust()->client();
        $wiring = TrustChecks::wiring(new MockHttpClient(function (string $method, string $url, array $options) use ($identity, $status): ResponseInterface {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (!str_contains($path, '/issues/7/labels')) {
                return $identity->request($method, $url, $options);
            }
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $this->writes[] = trim($method.' '.$path.' '.$body);

            return new MockResponse('[]', ['http_code' => $status]);
        }, 'https://api.github.com'));

        return new GitHubLabelWrites($wiring->calls, $wiring->tokens);
    }
}
