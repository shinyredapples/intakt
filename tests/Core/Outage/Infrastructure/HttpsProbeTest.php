<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Infrastructure\HttpsProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Uri\Rfc3986\Uri;

#[CoversClass(HttpsProbe::class)]
final class HttpsProbeTest extends TestCase
{
    public function testAClientErrorStatusCountsAsAnAnswer(): void
    {
        $probe = new HttpsProbe(new MockHttpClient(new MockResponse('', ['http_code' => 401])), new Uri('https://api.example'), 3.0);

        self::assertTrue($probe->answers());
    }

    public function testATimeoutCountsAsNoAnswer(): void
    {
        $probe = new HttpsProbe(new MockHttpClient(new MockResponse('', ['error' => 'Idle timeout reached'])), new Uri('https://api.example'), 3.0);

        self::assertFalse($probe->answers());
    }

    public function testTheProbeIsABareHeadWithNoCredentialAndNoBody(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        new HttpsProbe(new MockHttpClient($response), new Uri('https://api.example'), 3.0)->answers();

        self::assertSame('HEAD', $response->getRequestMethod());
        self::assertSame('https://api.example/', $response->getRequestUrl());
        self::assertEmpty($response->getRequestOptions()['body'] ?? '');
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertSame([], preg_grep('/^(authorization|x-api-key|cookie)\s*:/i', $headers));
    }

    public function testUserinfoInTheUrlIsNeitherSentAsBasicAuthNorKeptInTheUrl(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        new HttpsProbe(new MockHttpClient($response), new Uri('https://user:secret@llm.example/v1'), 3.0)->answers();

        self::assertSame('https://llm.example/', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertSame([], preg_grep('/^authorization\s*:/i', $headers));
    }

    public function testOnlyTheOriginIsRequestedSoAPathOrQueryCarryingACredentialIsNotSent(): void
    {
        $response = new MockResponse('', ['http_code' => 500]);
        $probe = new HttpsProbe(new MockHttpClient($response), new Uri('https://model.example:8443/gateway/synthetic-path/v1?api_key=synthetic-query#part'), 3.0);

        self::assertTrue($probe->answers());
        self::assertSame('https://model.example:8443/', $response->getRequestUrl());
    }

    public function testTheProbeWaitsAtMostItsTimeout(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        new HttpsProbe(new MockHttpClient($response), new Uri('https://api.example'), 3.0)->answers();

        self::assertSame(3.0, $response->getRequestOptions()['timeout']);
        self::assertSame(3.0, $response->getRequestOptions()['max_duration']);
    }
}
