<?php

declare(strict_types=1);

namespace Intakt\Upstream\WorkerModeState;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class Verdict
{
    public string $label = '';
}

#[McpTool(name: 'list_projects', description: 'Lists projects')]
final class ListProjectsTool
{
    public function __invoke(): string
    {
        return 'owner answer';
    }
}

#[McpTool(name: 'read_file', description: 'Reads a file')]
final class ReadFileTool
{
    public function __invoke(): string
    {
        return 'review answer';
    }
}

// The provider's side of the wire: a structured request gets JSON, a plain one names the model it was sent for, so a leaked option or model shows in the answer.
final class ProviderAnswers
{
    /**
     * @param array{body: string} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);        $text = isset($request['response_format']) ? '{"label":"ok"}' : 'answer of '.$request['model'];
        $answer = ['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];

        return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}

final readonly class AskController
{
    public function __construct(
        #[Autowire(service: 'ai.platform.generic.probe')]
        private PlatformInterface $platform,
    ) {
    }

    public function ask(string $model): Response
    {
        return new Response($this->platform->invoke($model, new MessageBag(Message::ofUser('hello')))->asText());
    }

    public function judge(string $model): Response
    {
        $verdict = $this->platform->invoke($model, new MessageBag(Message::ofUser('judge')), ['response_format' => Verdict::class])->asObject();

        return new Response($verdict instanceof Verdict ? 'verdict '.$verdict->label : 'no verdict');
    }
}

// The bundles Intakt runs in its worker, wired as Intakt wires them (a generic platform, two MCP servers with file sessions and the session lock), and nothing else.
final class ProbeKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly string $varDirectory,
    ) {
        parent::__construct('probe', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new AiBundle(), new McpBundle()];
    }

    public function getCacheDir(): string
    {
        return $this->varDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDirectory.'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'probe', 'test' => false, 'http_method_override' => false, 'property_info' => ['enabled' => true]]);
        $container->extension('security', ['firewalls' => ['main' => ['security' => false]]]);
        $container->extension('ai', ['platform' => ['generic' => ['probe' => ['base_url' => 'https://localhost', 'api_key' => 'key', 'http_client' => 'probe.http_client']]]]);
        $session = ['store' => 'file', 'lock' => ['enabled' => true]];
        $container->extension('mcp', ['servers' => [
            'owner' => ['http' => ['path' => '/mcp/owner'], 'session' => $session, 'registry' => ['tools' => [ListProjectsTool::class]]],
            'review' => ['http' => ['path' => '/mcp/review'], 'session' => $session, 'registry' => ['tools' => [ReadFileTool::class]]],
        ]]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ProviderAnswers::class);
        $services->set('probe.http_client', MockHttpClient::class)->args([service(ProviderAnswers::class), 'https://localhost']);
        $services->set(AskController::class)->public()->tag('controller.service_arguments');
        $services->set(ListProjectsTool::class);
        $services->set(ReadFileTool::class);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('probe_ask', '/ask/{model}')->controller([AskController::class, 'ask']);
        $routes->add('probe_judge', '/judge/{model}')->controller([AskController::class, 'judge']);
        $routes->import('.', 'mcp');
    }
}

// Sends each request through one booted kernel and terminates it, as a FrankenPHP worker does; the kernel resets its services before the next one.
final readonly class Worker
{
    public function __construct(
        private ProbeKernel $kernel,
    ) {
    }

    public function get(string $path): string
    {
        return $this->send(Request::create('http://localhost'.$path))['body'];
    }

    /**
     * @return array{status: int, session: string, body: string}
     */
    public function mcp(string $server, ?string $session, string $body): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        if (null !== $session) {
            $headers['HTTP_MCP_SESSION_ID'] = $session;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
        }

        return $this->send(Request::create('http://localhost/mcp/'.$server, 'POST', server: $headers, content: $body));
    }

    public function open(string $server): string
    {
        $initialize = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"probe","version":"1.0.0"}}}';
        $session = $this->mcp($server, null, $initialize)['session'];
        $this->mcp($server, $session, '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        return $session;
    }

    /**
     * @return array{status: int, session: string, body: string}
     */
    private function send(Request $request): array
    {
        $response = $this->kernel->handle($request);
        // A streamed answer is flushed between events, so a collecting callback keeps what a plain buffer would pass on.
        $sent = '';
        ob_start(static function (string $chunk) use (&$sent): string {
            $sent .= $chunk;

            return '';
        });
        $response->sendContent();
        ob_end_clean();
        $this->kernel->terminate($request, $response);

        return ['status' => $response->getStatusCode(), 'session' => (string) $response->headers->get('Mcp-Session-Id'), 'body' => $sent];
    }
}

#[CoversNothing]
final class WorkerModeStateTest extends TestCase
{
    // Warm-up fills lazy caches (the container's services, the serializer, the session files); what is measured afterwards must not keep growing.
    private const int WARM_UP_CALLS = 100;

    private const int CALLS = 1_000;

    // Room for the allocator to settle; a service that keeps a 20-byte string on every second call grows by about 36 KB over the 900 measured calls.
    private const int MEMORY_TOLERANCE_BYTES = 16_384;

    private string $varDirectory;

    private ProbeKernel $kernel;

    protected function setUp(): void
    {
        $this->varDirectory = sys_get_temp_dir().'/intakt-worker-probe-'.bin2hex(random_bytes(4));
        $this->kernel = new ProbeKernel($this->varDirectory);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove($this->varDirectory);
    }

    public function testASecondRequestInTheSameKernelNeverSeesTheFirstRequestsModel(): void
    {
        $worker = new Worker($this->kernel);

        foreach (['model-a', 'model-b', 'model-a', 'model-b'] as $model) {
            self::assertSame('answer of '.$model, $worker->get('/ask/'.$model));
        }
    }

    public function testAPlainRequestAfterAStructuredOneGetsTextNotTheEarlierObject(): void
    {
        $worker = new Worker($this->kernel);

        $verdict = $worker->get('/judge/model-a');
        $plain = $worker->get('/ask/model-b');

        self::assertSame('verdict ok', $verdict);
        self::assertSame('answer of model-b', $plain);
    }

    public function testASecondMcpSessionNeverListsTheFirstSessionsTools(): void
    {
        $worker = new Worker($this->kernel);
        $sessions = [['owner', $worker->open('owner')], ['review', $worker->open('review')], ['owner', $worker->open('owner')], ['review', $worker->open('review')]];

        foreach ($sessions as [$server, $session]) {
            $names = $this->toolNames($worker->mcp($server, $session, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')['body']);

            self::assertSame('owner' === $server ? ['list_projects'] : ['read_file'], $names);
        }
    }

    public function testASessionOfOneServerIsRefusedByTheOtherServer(): void
    {
        $worker = new Worker($this->kernel);
        $ownerSession = $worker->open('owner');

        $answer = $worker->mcp('review', $ownerSession, '{"jsonrpc":"2.0","id":3,"method":"tools/list"}');

        self::assertGreaterThanOrEqual(400, $answer['status']);
        self::assertStringNotContainsString('list_projects', $answer['body']);
        self::assertStringNotContainsString('read_file', $answer['body']);
    }

    public function testMemoryStaysFlatOverOneThousandAlternatingCalls(): void
    {
        $worker = new Worker($this->kernel);
        $sessions = ['owner' => $worker->open('owner'), 'review' => $worker->open('review')];
        $atWarmUp = 0;

        for ($call = 1; $call <= self::CALLS; ++$call) {
            $this->callOnce($worker, $sessions, $call);

            if (self::WARM_UP_CALLS === $call) {
                gc_collect_cycles();
                $atWarmUp = memory_get_usage();
            }
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $atWarmUp;

        self::assertLessThan(self::MEMORY_TOLERANCE_BYTES, $growth, sprintf('memory grew by %d bytes between call %d and call %d', $growth, self::WARM_UP_CALLS, self::CALLS));
    }

    /**
     * @param array{owner: string, review: string} $sessions
     */
    private function callOnce(Worker $worker, array $sessions, int $call): void
    {
        match ($call % 4) {
            0 => self::assertSame('answer of model-a', $worker->get('/ask/model-a')),
            1 => self::assertStringContainsString('owner answer', $worker->mcp('owner', $sessions['owner'], $this->toolCall('list_projects', $call))['body']),
            2 => self::assertSame('answer of model-b', $worker->get('/ask/model-b')),
            3 => self::assertStringContainsString('review answer', $worker->mcp('review', $sessions['review'], $this->toolCall('read_file', $call))['body']),
        };
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $body): array
    {
        $decoded = json_decode(preg_replace('/^.*?data: /s', '', $body) ?? '', true, flags: JSON_THROW_ON_ERROR);

        return array_column($decoded['result']['tools'], 'name');
    }

    private function toolCall(string $tool, int $id): string
    {
        return sprintf('{"jsonrpc":"2.0","id":%d,"method":"tools/call","params":{"name":"%s","arguments":{}}}', $id, $tool);
    }
}
