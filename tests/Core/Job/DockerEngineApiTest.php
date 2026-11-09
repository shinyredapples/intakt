<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\DockerEngineApi;
use Intakt\Core\Job\Infrastructure\DockerEngineError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[CoversClass(DockerEngineApi::class)]
#[CoversClass(DockerEngineError::class)]
final class DockerEngineApiTest extends TestCase
{
    public function testLogsOfSeveralFramesAreJoinedWithoutTheirHeaders(): void
    {
        $framed = "\x01\x00\x00\x00".pack('N', 3).'ab'."\n\x02\x00\x00\x00".pack('N', 2).'c'."\n";

        $api = $this->apiAnswering(new MockResponse($framed));

        self::assertSame("ab\nc\n", $api->logs('c1'));
    }

    public function testRemovingAContainerThatIsAlreadyGoneIsNotAnError(): void
    {
        $this->apiAnswering(new MockResponse('{}', ['http_code' => 404]))->removeContainer('c1');

        $this->addToAssertionCount(1);
    }

    public function testARefusedCallThrowsInsteadOfPassingAsDone(): void
    {
        $api = $this->apiAnswering(new MockResponse('{"message":"boom"}', ['http_code' => 500]));

        $this->expectException(HttpExceptionInterface::class);

        $api->start('c1');
    }

    public function testACreateThatAnswersWithoutAnIdFails(): void
    {
        $api = $this->apiAnswering(new MockResponse('{}', ['http_code' => 201]));

        $this->expectException(DockerEngineError::class);

        $api->createContainer('x', []);
    }

    public function testAWaitThatAnswersWithoutAStatusCodeFails(): void
    {
        $api = $this->apiAnswering(new MockResponse('{}'));

        $this->expectException(DockerEngineError::class);

        $api->wait('c1', 5);
    }

    public function testAnItemListedWithoutTheJobLabelValueIsIgnored(): void
    {
        $api = $this->apiAnswering(new MockResponse('[{"Id":"c1","Labels":{}},{"Id":"c2","Labels":{"intakt.job":"j"}},"junk"]'));

        self::assertSame(['c2'], array_map(static fn ($resource): string => $resource->id, $api->jobContainers()));
    }

    private function apiAnswering(MockResponse $response): DockerEngineApi
    {
        return new DockerEngineApi('/unused.sock', new MockHttpClient($response));
    }
}
