<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\JobContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DockerEngine::class)]
#[CoversClass(DockerEngineFailed::class)]
#[UsesClass(JobContainer::class)]
#[UsesClass(JobId::class)]
final class DockerEngineTest extends TestCase
{
    public function testOnlyContainersLabeledWithAPlainJobIdAreListed(): void
    {
        $engine = $this->engineAnswering(json_encode([
            ['Id' => 'abc123', 'Labels' => ['intakt.job' => 'build-1']],
            ['Id' => 'def456', 'Labels' => ['intakt.job' => '../etc']],
            ['Id' => 'fed789', 'Labels' => []],
        ], JSON_THROW_ON_ERROR));

        self::assertEquals([new JobContainer('abc123', new JobId('build-1'))], $engine->jobContainers());
    }

    public function testAContainerIdThatCouldLeaveTheApiPathIsRefused(): void
    {
        $engine = $this->engineAnswering('{"Id":"../../images"}', 201);

        $this->expectExceptionObject(DockerEngineFailed::unexpected('containers/create'));

        $engine->create('intakt-job-build-1', []);
    }

    public function testARefusalCarriesTheEnginesMessage(): void
    {
        $engine = $this->engineAnswering('{"message":"No such image: sample:latest"}', 404);

        $this->expectExceptionObject(DockerEngineFailed::refused('containers/create', 404, 'No such image: sample:latest'));

        $engine->create('intakt-job-build-1', []);
    }

    public function testAListingThatIsNotAListIsRefused(): void
    {
        $engine = $this->engineAnswering('{"containers":[]}');

        $this->expectExceptionObject(DockerEngineFailed::unexpected('containers/json'));

        $engine->jobContainers();
    }

    public function testAContainerAlreadyGoneCountsAsRemoved(): void
    {
        $engine = $this->engineAnswering('{"message":"No such container"}', 404);

        $engine->remove('abc123');

        $this->expectNotToPerformAssertions();
    }

    public function testAWaitRefusedByTheEngineFails(): void
    {
        $engine = $this->engineAnswering('{"message":"No such container"}', 404);

        $this->expectExceptionObject(DockerEngineFailed::refused('containers/wait', 404, ''));

        $engine->waitForExit('abc123', 60);
    }

    public function testAWaitWithoutTheSocketFailsInsteadOfReadingAsADeadline(): void
    {
        $engine = new DockerEngine(new MockHttpClient(new MockResponse('{"StatusCode":0}')), FakeDockerEngine::SOCKET.'.missing');

        $this->expectExceptionObject(DockerEngineFailed::noSocket(FakeDockerEngine::SOCKET.'.missing'));

        $engine->waitForExit('abc123', 60);
    }

    private function engineAnswering(string $body, int $status = 200): DockerEngine
    {
        return new DockerEngine(new MockHttpClient(new MockResponse($body, ['http_code' => $status])), FakeDockerEngine::SOCKET);
    }
}
