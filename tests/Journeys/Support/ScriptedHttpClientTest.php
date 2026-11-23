<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ScriptedHttpClient::class)]
#[UsesClass(ScriptDirectory::class)]
#[UsesClass(ScriptedCall::class)]
#[UsesClass(ScriptNotArmed::class)]
#[UsesClass(KeptScript::class)]
#[UsesClass(ProcParents::class)]
#[UsesClass(ProcessEntry::class)]
final class ScriptedHttpClientTest extends TestCase
{
    private string $path;

    public function testWithoutAScriptDirectoryEveryCallGoesToTheRealClient(): void
    {
        $client = new ScriptedHttpClient(new MockHttpClient(new MockResponse('real')), new MockClock(), '');

        self::assertSame('real', $client->request('POST', 'https://localhost/v1/chat/completions')->getContent());
    }

    public function testACallIsAnsweredFromTheScriptAndHeldForItsWeight(): void
    {
        $clock = new MockClock('@1000');
        new ScriptDirectory($this->path, $clock)->arm(2.0, [['content_type' => 'application/json', 'body' => '{"answer":1}']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);

        $response = $client->request('POST', 'https://localhost/v1/chat/completions');

        self::assertSame('{"answer":1}', $response->getContent());
        self::assertSame(['application/json'], $response->getHeaders()['content-type']);
        self::assertEquals([new ScriptedCall(1, (int) getmypid(), (int) new ProcParents()->startOf((int) getmypid()), 1000.0, 1002.0)], new ScriptDirectory($this->path, $clock)->calls());
    }

    public function testTheScriptReadByTheFirstCallStaysForTheWholeProcess(): void
    {
        $clock = new MockClock('@1000');
        $scripts = new ScriptDirectory($this->path, $clock);
        $scripts->arm(1.0, [['content_type' => 'text/plain', 'body' => 'one'], ['content_type' => 'text/plain', 'body' => 'two']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);
        $first = $client->request('POST', 'https://localhost/a')->getContent();
        $scripts->arm(5.0, [['content_type' => 'text/plain', 'body' => 'armed again']]);

        $second = $client->request('POST', 'https://localhost/b')->getContent();

        self::assertSame(['one', 'two'], [$first, $second]);
        self::assertSame(1002, $clock->now()->getTimestamp());
    }

    public function testACallWithoutAnArmedScriptFails(): void
    {
        $client = new ScriptedHttpClient(new MockHttpClient(), new MockClock(), $this->path);

        $this->expectException(ScriptNotArmed::class);

        $client->request('POST', 'https://localhost/v1/chat/completions')->getContent();
    }

    public function testAClientWithOtherOptionsKeepsAnsweringFromTheScript(): void
    {
        $clock = new MockClock('@1000');
        new ScriptDirectory($this->path, $clock)->arm(0.0, [['content_type' => 'text/plain', 'body' => 'scripted']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path)->withOptions(['timeout' => 5]);

        self::assertSame('scripted', $client->request('GET', 'https://localhost/')->getContent());
    }

    public function testAScriptedRequestKeepsTheBaseUriGivenThroughOtherOptions(): void
    {
        $clock = new MockClock('@1000');
        new ScriptDirectory($this->path, $clock)->arm(0.0, [['content_type' => 'text/plain', 'body' => 'scripted']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path)->withOptions(['base_uri' => 'https://localhost/']);

        self::assertSame('https://localhost/sample', $client->request('GET', '/sample')->getInfo('url'));
    }

    public function testAClientWithOtherOptionsKeepsTheScriptItsOriginReadBeforeTheScriptWasArmedAgain(): void
    {
        $clock = new MockClock('@1000');
        $scripts = new ScriptDirectory($this->path, $clock);
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'original']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);
        $client->request('GET', 'https://localhost/')->getContent();
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'armed again']]);

        self::assertSame('original', $client->withOptions(['timeout' => 5])->request('GET', 'https://localhost/')->getContent());
    }

    public function testTheOriginKeepsTheScriptAClientWithOtherOptionsReadFirst(): void
    {
        $clock = new MockClock('@1000');
        $scripts = new ScriptDirectory($this->path, $clock);
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'original']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);
        $client->withOptions(['timeout' => 5])->request('GET', 'https://localhost/')->getContent();
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'armed again']]);

        self::assertSame('original', $client->request('GET', 'https://localhost/')->getContent());
    }

    public function testAResetIsPassedOnToTheRealClient(): void
    {
        $real = new MockHttpClient(new MockResponse('real'));
        $client = new ScriptedHttpClient($real, new MockClock(), '');
        $client->request('GET', 'https://localhost/')->getContent();

        $client->reset();

        self::assertSame(0, $real->getRequestsCount());
    }

    public function testAResetKeepsTheScriptReadFirst(): void
    {
        $clock = new MockClock('@1000');
        $scripts = new ScriptDirectory($this->path, $clock);
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'original']]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);
        $client->request('GET', 'https://localhost/')->getContent();
        $scripts->arm(0.0, [['content_type' => 'text/plain', 'body' => 'armed again']]);

        $client->reset();

        self::assertSame('original', $client->request('GET', 'https://localhost/')->getContent());
    }

    public function testAScriptedResponseIsStreamedLikeAnyOther(): void
    {
        $clock = new MockClock('@1000');
        new ScriptDirectory($this->path, $clock)->arm(0.0, [['content_type' => 'text/event-stream', 'body' => "data: one\n\n"]]);
        $client = new ScriptedHttpClient(new MockHttpClient(), $clock, $this->path);
        $response = $client->request('GET', 'https://localhost/');
        $chunks = '';

        foreach ($client->stream($response) as $chunk) {
            $chunks .= $chunk->getContent();
        }

        self::assertSame("data: one\n\n", $chunks);
    }

    protected function setUp(): void
    {
        $this->path = new Filesystem()->tempnam(sys_get_temp_dir(), 'scripts-');
        new Filesystem()->remove($this->path);
        new Filesystem()->mkdir($this->path);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->path);
    }
}
