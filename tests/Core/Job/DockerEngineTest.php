<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\JobContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DockerEngine::class)]
#[CoversClass(DockerEngineFailed::class)]
#[CoversClass(EngineAnswer::class)]
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

    public function testAWaitAnsweredWithAnExitCodeCountsAsAnAnswer(): void
    {
        self::assertTrue($this->engineAnswering('{"StatusCode":0}')->waitForExit('abc123', 60));
    }

    public function testAWaitAnsweredWithAnErrorCountsAsNoAnswer(): void
    {
        self::assertFalse($this->engineAnswering('{"StatusCode":-1,"Error":{"Message":"context canceled"}}')->waitForExit('abc123', 60));
    }

    public function testAWaitAnsweredWithoutAnExitCodeCountsAsNoAnswer(): void
    {
        self::assertFalse($this->engineAnswering('')->waitForExit('abc123', 60));
        self::assertFalse($this->engineAnswering('{"StatusCode":"0"}')->waitForExit('abc123', 60));
        self::assertFalse($this->engineAnswering('not json')->waitForExit('abc123', 60));
    }

    public function testRemovalAlsoRemovesTheContainersAnonymousVolumes(): void
    {
        $url = '';
        $engine = $this->engineRecording($url, '', 204);

        $engine->remove('abc123');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['force' => 'true', 'v' => 'true'], $query);
    }

    public function testAnImageWithoutVolumesDeclaresNone(): void
    {
        self::assertSame([], $this->engineAnswering('{"Config":{"Volumes":null}}')->imageVolumes('sample:latest'));
        self::assertSame([], $this->engineAnswering('{"Config":{}}')->imageVolumes('sample:latest'));
    }

    public function testTheVolumesAnImageDeclaresAreListed(): void
    {
        self::assertSame(['/data'], $this->engineAnswering('{"Config":{"Volumes":{"/data":{}}}}')->imageVolumes('sample:latest'));
    }

    public function testAnImageAnswerWithoutAConfigIsRefused(): void
    {
        $this->expectExceptionObject(DockerEngineFailed::unexpected('images/json'));

        $this->engineAnswering('{"Id":"sha256:abc"}')->imageVolumes('sample:latest');
    }

    public function testTheImageNameIsEncodedIntoTheApiPath(): void
    {
        $url = '';
        $engine = $this->engineRecording($url, '{"Config":{"Volumes":null}}');

        $engine->imageVolumes('localhost:5000/sample:latest');

        self::assertSame('/v1.44/images/localhost%3A5000%2Fsample%3Alatest/json', parse_url($url, PHP_URL_PATH));
    }

    public function testTheStandardErrorLogAsksForStandardErrorAloneAndTheWholeLog(): void
    {
        $url = '';
        $engine = $this->engineRecording($url, '');

        $engine->standardErrorHead('abc123', 64);

        self::assertSame('/v1.44/containers/abc123/logs', parse_url($url, PHP_URL_PATH));
        self::assertSame('stderr=true', parse_url($url, PHP_URL_QUERY));
    }

    public function testTheStandardErrorLogIsReadWholeUpToItsLimit(): void
    {
        $engine = $this->engineAnswering(pack('CxxxN', 2, 8).'one two'."\n");

        self::assertSame("one two\n", $engine->standardErrorHead('abc123', 16));
    }

    public function testReadingTheStandardErrorLogStopsAtItsLimitAndKeepsTheStartOfACutFrame(): void
    {
        $answer = new MockResponse([pack('CxxxN', 2, 4).'one'."\n", pack('CxxxN', 2, 100).'two', str_repeat('x', 97)]);
        $engine = new DockerEngine(new MockHttpClient($answer), FakeDockerEngine::SOCKET);

        self::assertSame("one\ntw", $engine->standardErrorHead('abc123', 22));
    }

    public function testAHeaderTheLimitCutsIsDropped(): void
    {
        $engine = $this->engineAnswering(pack('CxxxN', 2, 4)."one\n".pack('CxxxN', 2, 4)."two\n");

        self::assertSame("one\n", $engine->standardErrorHead('abc123', 15));
    }

    public function testAStandardErrorLogTheEngineRefusesFails(): void
    {
        $engine = $this->engineAnswering('{"message":"configured logging driver does not support reading"}', 501);

        $this->expectExceptionObject(DockerEngineFailed::refused('containers/abc123/logs', 501, 'configured logging driver does not support reading'));

        $engine->standardErrorHead('abc123', 64);
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

    private function engineRecording(string &$url, string $body, int $status = 200): DockerEngine
    {
        $client = new MockHttpClient(static function (string $method, string $requested) use (&$url, $body, $status): MockResponse {
            $url = $requested;

            return new MockResponse($body, ['http_code' => $status]);
        });

        return new DockerEngine($client, FakeDockerEngine::SOCKET);
    }
}
