<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers nothing because the contract is the wiring: the bundle's server, Intakt's wrapper and voter in one kernel.
#[CoversNothing]
final class ToolAuthorizationContractTest extends KernelTestCase
{
    public function testAToolTheVoterRefusesDoesNotRunOnTheOwnerServer(): void
    {
        $answer = $this->client()->call('tools/call', ['name' => 'test_write_probe', 'arguments' => new stdClass()], 2);

        self::assertTrue(OwnerServerClient::at($answer, 'result', 'isError'));
        self::assertSame(
            [['type' => 'text', 'text' => 'This tool needs mcp_tool_write, which is not granted.']],
            OwnerServerClient::at($answer, 'result', 'content'),
        );
    }

    public function testAToolTheVoterGrantsRunsOnTheOwnerServer(): void
    {
        $answer = $this->client()->call('tools/call', ['name' => 'list_projects', 'arguments' => new stdClass()], 2);

        self::assertFalse(OwnerServerClient::at($answer, 'result', 'isError'));
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
