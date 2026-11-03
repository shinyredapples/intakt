<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers nothing because the contract is the behavior of the MCP Bundle and SDK as Intakt configures them.
#[CoversNothing]
final class ProtocolVersionContractTest extends KernelTestCase
{
    // The revision Claude Code sends in its initialize request, because the server must echo it.
    private const string CLAUDE_CODE_VERSION = '2025-11-25';

    public function testInitializeWithTheVersionOfClaudeCodeGetsThatVersionBack(): void
    {
        $answer = $this->client()->initialize(self::CLAUDE_CODE_VERSION);

        self::assertSame(self::CLAUDE_CODE_VERSION, McpServerClient::at($answer, 'result', 'protocolVersion'));
        self::assertSame('intakt', McpServerClient::at($answer, 'result', 'serverInfo', 'name'));
    }

    public function testInitializeWithAnUnknownVersionGetsAVersionTheServerSpeaks(): void
    {
        $answer = $this->client()->initialize('1999-01-01');
        $version = McpServerClient::at($answer, 'result', 'protocolVersion');

        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $version);
        self::assertNotSame('1999-01-01', $version);
    }

    private function client(): McpServerClient
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);

        return new McpServerClient($kernel);
    }
}
