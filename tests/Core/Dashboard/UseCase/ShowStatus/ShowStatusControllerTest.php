<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\MessageQueues;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ShowStatusController;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\WebTestCase;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;

// Through the kernel and the Doctrine ledger and queue, because the evidence of the status is this endpoint on a running install.
#[Group('persistence')]
#[CoversClass(ShowStatusController::class)]
#[UsesClass(StatusReadModel::class)]
#[UsesClass(ProjectStatuses::class)]
#[UsesClass(MessageQueues::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(FilterProjects::class)]
final class ShowStatusControllerTest extends WebTestCase
{
    private const string SCHEMA = __DIR__.'/../../../../../docs/user/status.schema.json';

    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';

    private string $configuredDsn;
    private KernelBrowser $browser;

    public function testTheStatusEndpointAnswersJsonThatValidatesAgainstTheDocumentedSchema(): void
    {
        $this->browser->request('GET', '/status');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([], $this->schemaErrorsOf((string) $this->browser->getResponse()->getContent()));
    }

    public function testAStoppedConsumerStillYieldsAStatusWithTheMessageWaiting(): void
    {
        $this->queue()->send(new Envelope(new stdClass()));

        $this->browser->request('GET', '/status');

        self::assertResponseIsSuccessful();
        $status = (string) $this->browser->getResponse()->getContent();
        self::assertSame([], $this->schemaErrorsOf($status));
        self::assertStringContainsString('"queues":[{"name":"async","messages":1}', $status);
    }

    public function testTheEndpointServesTheSameDocumentAsTheReadModel(): void
    {
        self::clockAt('2026-11-18T10:00:00+00:00');
        $model = self::getContainer()->get(StatusReadModel::class);
        self::assertInstanceOf(StatusReadModel::class, $model);

        $this->browser->request('GET', '/status');

        self::assertSame($model->json(), $this->browser->getResponse()->getContent());
    }

    public function testTheStatusEndpointRefusesAHostThatDoesNotNameTheDashboard(): void
    {
        $this->browser->request('GET', '/status', server: ['HTTP_HOST' => 'attacker.example']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringNotContainsString('"projects"', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheStatusEndpointRefusesALoopbackHostWithTheWrongPort(): void
    {
        $this->browser->request('GET', '/status', server: ['HTTP_HOST' => 'localhost:9999']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringNotContainsString('"projects"', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheStatusEndpointRefusesAPostRequest(): void
    {
        $this->browser->request('POST', '/status');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTheStatusEndpointAnswersAHeadRequestWithoutABody(): void
    {
        $this->browser->request('HEAD', '/status');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('', (string) $this->browser->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? '';
        $this->configuredDsn = is_string($configured) ? $configured : '';
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';

        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->queue()->setup();
    }

    protected function tearDown(): void
    {
        $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;

        parent::tearDown();
    }

    private function queue(): DoctrineTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(DoctrineTransport::class, $queue);

        return $queue;
    }

    /**
     * @return list<string>
     */
    private function schemaErrorsOf(string $json): array
    {
        $document = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $validator->validate($document, (object) ['$ref' => 'file://'.realpath(self::SCHEMA)]);

        /** @var list<array{property: string, message: string}> $errors */
        $errors = $validator->getErrors();

        return array_map(static fn (array $error): string => $error['property'].': '.$error['message'], $errors);
    }
}
