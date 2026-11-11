<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\ImageBuild;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DockerEngine::class)]
#[CoversClass(EngineAnswer::class)]
#[CoversClass(ImageBuild::class)]
#[UsesClass(DockerEngineFailed::class)]
final class DockerEngineBuildTest extends TestCase
{
    public function testTheContextIsSentWithTheTagAndTheStreamBecomesTheOutput(): void
    {
        $response = new MockResponse('{"stream":"Step 1/2 : FROM php:8.5-cli\n"}'."\r\n".'{"aux":{"ID":"sha256:abc"}}'."\n".'{"stream":"Successfully tagged intakt-job/demo:main\n"}'."\n");

        $build = $this->engineAnswering($response)->build('tar bytes', 'intakt-job/demo:main', '.devcontainer/Dockerfile');

        self::assertSame("Step 1/2 : FROM php:8.5-cli\nSuccessfully tagged intakt-job/demo:main\n", $build->output);
        self::assertFalse($build->failed());
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://docker/v1.44/build?t=intakt-job/demo:main&dockerfile=.devcontainer/Dockerfile&rm=true&forcerm=true', $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertSame('tar bytes', $options['body']);
        self::assertIsArray($options['headers']);
        self::assertContains('Content-Type: application/x-tar', $options['headers']);
    }

    public function testAFailedStepIsTheBuildsError(): void
    {
        $response = new MockResponse('{"stream":"missing PHP extension: nosuchext\n"}'."\n".'{"errorDetail":{"code":1,"message":"returned a non-zero code: 1"},"error":"returned a non-zero code: 1"}'."\n");

        $build = $this->engineAnswering($response)->build('tar', 'demo', 'Dockerfile');

        self::assertTrue($build->failed());
        self::assertSame('returned a non-zero code: 1', $build->error);
        self::assertSame("missing PHP extension: nosuchext\n", $build->output);
    }

    public function testARefusedBuildFailsWithTheEnginesMessage(): void
    {
        $response = new MockResponse('{"message":"invalid reference format"}', ['http_code' => 400]);

        $this->expectExceptionObject(DockerEngineFailed::refused('build', 400, 'invalid reference format'));

        $this->engineAnswering($response)->build('tar', 'demo', 'Dockerfile');
    }

    public function testALineThatIsNoJsonObjectIsUnexpected(): void
    {
        $response = new MockResponse('{"stream":"ok\n"}'."\n".'garbled'."\n");

        $this->expectExceptionObject(DockerEngineFailed::unexpected('build'));

        $this->engineAnswering($response)->build('tar', 'demo', 'Dockerfile');
    }

    public function testAnErrorThatIsNoTextIsUnexpected(): void
    {
        $response = new MockResponse('{"error":{"code":1}}'."\n");

        $this->expectExceptionObject(DockerEngineFailed::unexpected('build'));

        $this->engineAnswering($response)->build('tar', 'demo', 'Dockerfile');
    }

    private function engineAnswering(MockResponse $response): DockerEngine
    {
        return new DockerEngine(new MockHttpClient($response), FakeDockerEngine::SOCKET);
    }
}
