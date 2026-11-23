<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Uri\Rfc3986\Uri;

// Because a mock that loads a missing asset or a screenshot job for a missing page breaks the design documents.
#[CoversNothing]
final class DashboardMocksTest extends TestCase
{
    private const string MOCKS = '/docs/design/mocks';

    public function testEveryAssetThePagesAndTheSharedScriptsLoadExistsInsideTheMocksFolder(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $missing = [];

        foreach ($this->assetLinksByPage($mocks) as $page => $assets) {
            foreach ($assets as $asset) {
                // Because a path that climbs out of the mocks folder can exist on disk while a server rooted there answers it with 404.
                if (str_starts_with($asset, '../') || !is_file($mocks.'/'.$asset)) {
                    $missing[] = $page.': '.$asset;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryAssetLoadsOverTheServerTheReadmeDocuments(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $address = '127.0.0.1:'.$this->freePort();
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $mocks]);
        $server->start();

        try {
            $this->waitUntilServed('http://'.$address.'/projects.html');
            $failed = [];

            foreach ($this->assetLinksByPage($mocks) as $page => $assets) {
                $pageUrl = new Uri('http://'.$address.'/'.$page);
                foreach ($assets as $asset) {
                    // Resolved the way a browser does, because RFC 3986 drops the dot segments that climb above the root.
                    $status = $this->statusOf(new Uri($asset, $pageUrl)->toString());
                    if ('HTTP 200' !== $status) {
                        $failed[] = $page.': '.$asset.' -> '.$status;
                    }
                }
            }

            self::assertSame([], $failed);
        } finally {
            $server->stop();
        }
    }

    public function testEveryPageTheScreenshotGeneratorOpensExists(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $script = (string) file_get_contents($mocks.'/tools/screenshots.js');

        self::assertSame(1, preg_match('/const PAGES = \{(.*?)\};/s', $script, $block));
        preg_match_all("/^\\s*'?([a-z-]+)'?:/m", $block[1] ?? '', $pages);
        $missing = array_values(array_filter($pages[1], static fn (string $page): bool => !is_file($mocks.'/'.$page.'.html')));

        self::assertNotSame([], $pages[1]);
        self::assertSame([], $missing);
    }

    /**
     * @return array<string, list<string>>
     */
    private function assetLinksByPage(string $mocks): array
    {
        $pages = $this->filesMatching($mocks.'/*.html');
        $scripts = $this->filesMatching($mocks.'/assets/*.js');

        // The shared scripts write their markup into every page, so their links must count as links of each page that loads them.
        $shared = array_merge(...array_map($this->assetLinksIn(...), $scripts));
        $links = [];

        foreach ($pages as $page) {
            $links[basename($page)] = array_values(array_unique([...$this->assetLinksIn($page), ...$shared]));
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function filesMatching(string $pattern): array
    {
        $files = glob($pattern);
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function assetLinksIn(string $file): array
    {
        preg_match_all('/(?:href|src)="((?:\.\.\/)*assets\/[^"]+)"/', (string) file_get_contents($file), $matches);

        return $matches[1];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
        fclose($socket);

        return $port;
    }

    private function waitUntilServed(string $url): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if ('HTTP 200' === $this->statusOf($url)) {
                return;
            }
            usleep(50_000);
        }

        self::fail('The built-in server did not answer '.$url.' in time.');
    }

    private function statusOf(string $url): string
    {
        // Not file_get_contents(), because its warning on a refused connection fails the run while the server starts.
        try {
            return 'HTTP '.HttpClient::create()->request('GET', $url, ['timeout' => 2])->getStatusCode();
        } catch (TransportExceptionInterface) {
            return 'no response';
        }
    }
}
