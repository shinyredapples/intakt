<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Dashboard\Infrastructure\MessageQueues;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ShowStatusCommand;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusFormat;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;

// Through the kernel and the Doctrine ledger, because the evidence of the status is this command on a running install.
#[Group('persistence')]
#[CoversClass(ShowStatusCommand::class)]
#[CoversClass(StatusFormat::class)]
#[UsesClass(StatusReadModel::class)]
#[UsesClass(ProjectStatuses::class)]
#[UsesClass(MessageQueues::class)]
final class ShowStatusCommandTest extends KernelTestCase
{
    private const string SCHEMA = __DIR__.'/../../../../../docs/user/status.schema.json';

    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';

    private string $configuredDsn;
    private CommandTester $command;

    public function testTheJsonStatusValidatesAgainstTheDocumentedSchema(): void
    {
        $this->command->execute(['--format' => 'json']);

        $this->command->assertCommandIsSuccessful();
        self::assertSame([], $this->schemaErrorsOf($this->command->getDisplay()));
    }

    public function testAStoppedConsumerStillYieldsAStatusWithTheMessageWaiting(): void
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(DoctrineTransport::class, $queue);
        $queue->send(new Envelope(new stdClass()));

        $this->command->execute(['--format' => 'json']);

        $this->command->assertCommandIsSuccessful();
        self::assertSame([], $this->schemaErrorsOf($this->command->getDisplay()));
        self::assertStringContainsString('"queues":[{"name":"async","messages":1}', $this->command->getDisplay());
    }

    public function testJsonIsTheFormatWhenNoneIsGiven(): void
    {
        $this->command->execute([]);

        $this->command->assertCommandIsSuccessful();
        self::assertSame([], $this->schemaErrorsOf($this->command->getDisplay()));
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->command->execute(['--format' => 'yaml']);
    }

    public function testTheStatusIsOneLineOfJson(): void
    {
        $this->command->execute(['--format' => 'json']);

        self::assertSame(1, substr_count($this->command->getDisplay(), PHP_EOL));
        self::assertStringEndsWith('}'.PHP_EOL, $this->command->getDisplay());
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? '';
        $this->configuredDsn = is_string($configured) ? $configured : '';
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';

        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(DoctrineTransport::class, $queue);
        $queue->setup();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $this->command = new CommandTester(new Application($kernel)->find('intakt:status'));
    }

    protected function tearDown(): void
    {
        $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;

        parent::tearDown();
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
