<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Closure;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\ImageBuild;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const string IMAGE = 'sha256:2d2f1c0b5f6e4a3b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b';

    private string $sent = '';

    public function testTheContextIsSentWithTheTagAndTheStreamBecomesTheOutput(): void
    {
        $response = new MockResponse('{"stream":"Step 1/2 : FROM php:8.5-cli\n"}'."\r\n".'{"aux":{"ID":"'.self::IMAGE.'"}}'."\n".'{"stream":"Successfully tagged intakt-job/demo:main\n"}'."\n");

        $build = $this->engineAnswering($response)->build($this->context('tar ', 'bytes'), 'intakt-job/demo:main', '.devcontainer/Dockerfile');

        self::assertSame("Step 1/2 : FROM php:8.5-cli\nSuccessfully tagged intakt-job/demo:main\n", $build->output);
        self::assertFalse($build->failed());
        self::assertSame(self::IMAGE, $build->image);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://docker/v1.44/build?t=intakt-job/demo:main&dockerfile=.devcontainer/Dockerfile&rm=true&forcerm=true', $response->getRequestUrl());
        self::assertSame('tar bytes', $this->sent);
        $options = $response->getRequestOptions();
        self::assertIsArray($options['headers']);
        self::assertContains('Content-Type: application/x-tar', $options['headers']);
    }

    public function testAFailedStepIsTheBuildsError(): void
    {
        $response = new MockResponse('{"stream":"missing PHP extension: nosuchext\n"}'."\n".'{"errorDetail":{"code":1,"message":"returned a non-zero code: 1"},"error":"returned a non-zero code: 1"}'."\n");

        $build = $this->engineAnswering($response)->build($this->context('tar'), 'demo', 'Dockerfile');

        self::assertTrue($build->failed());
        self::assertSame('returned a non-zero code: 1', $build->error);
        self::assertSame("missing PHP extension: nosuchext\n", $build->output);
    }

    public function testARefusedBuildFailsWithTheEnginesMessage(): void
    {
        $response = new MockResponse('{"message":"invalid reference format"}', ['http_code' => 400]);

        $this->expectExceptionObject(DockerEngineFailed::refused('build', 400, 'invalid reference format'));

        $this->engineAnswering($response)->build($this->context('tar'), 'demo', 'Dockerfile');
    }

    public function testALineThatIsNoJsonObjectIsUnexpected(): void
    {
        $response = new MockResponse('{"stream":"ok\n"}'."\n".'garbled'."\n");

        $this->expectExceptionObject(DockerEngineFailed::unexpected('build'));

        $this->engineAnswering($response)->build($this->context('tar'), 'demo', 'Dockerfile');
    }

    public function testAnErrorThatIsNoTextIsUnexpected(): void
    {
        $response = new MockResponse('{"error":{"code":1}}'."\n");

        $this->expectExceptionObject(DockerEngineFailed::unexpected('build'));

        $this->engineAnswering($response)->build($this->context('tar'), 'demo', 'Dockerfile');
    }

    #[DataProvider('incompleteBuilds')]
    public function testABuildThatNamesNoBuiltImageIsUnexpected(string $body): void
    {
        $this->expectExceptionObject(DockerEngineFailed::unexpected('build'));

        $this->engineAnswering(new MockResponse($body))->build($this->context('tar'), 'demo', 'Dockerfile');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteBuilds(): iterable
    {
        yield 'empty' => [''];
        yield 'a list' => ['[]'];
        yield 'an empty object' => ['{}'];
        yield 'steps without an image id' => ['{"stream":"Step 1/2 : FROM php:8.5-cli\n"}'."\n"];
        yield 'an image id that is no digest' => ['{"aux":{"ID":"sha256:abc"}}'."\n"];
        yield 'a list line after the image id' => ['{"aux":{"ID":"'.self::IMAGE.'"}}'."\n".'[]'."\n"];
    }

    private function engineAnswering(MockResponse $response): DockerEngine
    {
        return new DockerEngine(new MockHttpClient($response), FakeDockerEngine::SOCKET);
    }

    /**
     * @return Closure(int): string
     */
    private function context(string ...$chunks): Closure
    {
        return function (int $size) use (&$chunks): string {
            $chunk = array_shift($chunks) ?? '';
            $this->sent .= $chunk;

            return $chunk;
        };
    }
}
