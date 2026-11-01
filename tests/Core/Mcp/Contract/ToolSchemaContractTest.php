<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers nothing because the contract is the behavior of the MCP Bundle and SDK as Intakt configures them.
#[CoversNothing]
final class ToolSchemaContractTest extends KernelTestCase
{
    public function testAToolWithoutParametersDeclaresThemAsAnEmptyPropertiesObject(): void
    {
        $client = $this->client();
        $this->listProjects($client);

        // Decoding to arrays turns {} into [], so the raw body decides: a client validates against an object.
        self::assertStringContainsString('"properties":{}', $client->lastBody());
        self::assertStringNotContainsString('"properties":[]', $client->lastBody());
    }

    public function testListProjectsIsAnnotatedReadOnlyAndNotDestructive(): void
    {
        $annotations = OwnerServerClient::at($this->listProjects($this->client()), 'annotations');

        self::assertIsArray($annotations);
        self::assertTrue($annotations['readOnlyHint']);
        self::assertFalse($annotations['destructiveHint']);
    }

    public function testListProjectsAnswersWithTheConfiguredProjects(): void
    {
        $answer = $this->client()->call('tools/call', ['name' => 'list_projects', 'arguments' => new \stdClass()], 2);

        self::assertFalse(OwnerServerClient::at($answer, 'result', 'isError'));
        self::assertIsList(OwnerServerClient::at($answer, 'result', 'structuredContent', 'projects'));
    }

    public function testAnUnknownToolIsAnMcpErrorWithoutAStackTrace(): void
    {
        $answer = $this->client()->call('tools/call', ['name' => 'no_such_tool', 'arguments' => new \stdClass()], 2);

        self::assertIsInt(OwnerServerClient::at($answer, 'error', 'code'));
        self::assertStringNotContainsString('#0', json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('trace', $answer);
    }

    /**
     * @return array<mixed>
     */
    private function listProjects(OwnerServerClient $client): array
    {
        $tools = OwnerServerClient::at($client->call('tools/list', [], 2), 'result', 'tools');
        self::assertIsArray($tools);

        $found = array_first(array_filter($tools, static fn (mixed $tool): bool => is_array($tool) && 'list_projects' === ($tool['name'] ?? null)));
        self::assertIsArray($found);

        return $found;
    }

    private function client(): OwnerServerClient
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $client = new OwnerServerClient($kernel);
        $client->initialize('2025-11-25');

        return $client;
    }
}
