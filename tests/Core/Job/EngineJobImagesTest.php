<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\EngineJobImages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(EngineJobImages::class)]
#[UsesClass(DockerEngine::class)]
#[UsesClass(EngineAnswer::class)]
final class EngineJobImagesTest extends TestCase
{
    public function testATagResolvesToTheIdTheEngineHoldsForItNow(): void
    {
        $id = 'sha256:'.str_repeat('0f', 32);
        $engine = new DockerEngine(new MockHttpClient(new MockResponse('{"Id":"'.$id.'"}')), FakeDockerEngine::SOCKET);

        self::assertSame($id, new EngineJobImages($engine)->idOf('sample:main'));
    }
}
