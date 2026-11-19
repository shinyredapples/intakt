<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ScriptedHttpClient::class)]
#[UsesClass(ScriptDirectory::class)]
#[UsesClass(CallRecords::class)]
final class ScriptedHttpClientTest extends TestCase
{
    private const string MESSAGES = 'https://api.anthropic.com/v1/messages';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-http-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testARequestGoesToTheInnerClientWhileNoScriptIsSet(): void
    {
        $client = new ScriptedHttpClient(new MockHttpClient(new MockResponse('inner')), null);

        self::assertSame('inner', $client->request('POST', self::MESSAGES)->getContent());
    }

    public function testOptionsReachTheInnerClient(): void
    {
        $inner = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse($url));
        $client = new ScriptedHttpClient($inner, null)->withOptions(['base_uri' => 'https://example.com/']);

        self::assertSame('https://example.com/path', $client->request('GET', 'path')->getContent());
    }

    public function testARequestToAnotherHostGoesToTheInnerClientEvenWithAScript(): void
    {
        $client = new ScriptedHttpClient(new MockHttpClient(new MockResponse('inner')), $this->directory);

        self::assertSame('inner', $client->request('GET', 'https://example.com/')->getContent());
    }

    public function testAnAnthropicRequestIsAnsweredWithAnApprovingStreamAndRecordedAsACall(): void
    {
        $client = new ScriptedHttpClient(new MockHttpClient(new MockResponse('inner')), $this->directory);

        $body = $client->request('POST', self::MESSAGES)->getContent();

        self::assertStringContainsString('event: message_stop', $body);
        self::assertStringContainsString('approve', str_replace('\\', '', $body));
        $calls = CallRecords::in($this->directory);
        self::assertSame([1, 1], [$calls->started(), $calls->ended()]);
    }

    public function testACallKeepsTheScriptedWeightInItsProcessAfterItEnded(): void
    {
        file_put_contents($this->directory.'/'.ScriptDirectory::WEIGHT, '4');
        $client = new ScriptedHttpClient(new MockHttpClient(new MockResponse('inner')), $this->directory);
        $before = memory_get_usage();

        $client->request('POST', self::MESSAGES)->getContent();

        self::assertGreaterThanOrEqual(4 * 1024 * 1024, memory_get_usage() - $before);
    }
}
