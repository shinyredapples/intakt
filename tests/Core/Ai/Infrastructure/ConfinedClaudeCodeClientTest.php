<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Infrastructure\ConfinedClaudeCodeClient;
use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;

#[CoversClass(ConfinedClaudeCodeClient::class)]
#[UsesClass(ReviewServerAccess::class)]
#[UsesClass(FinishedProcessResult::class)]
final class ConfinedClaudeCodeClientTest extends TestCase
{
    private const string CONNECTION = '{"mcpServers":{"intakt-review":{"type":"http","url":"http:\/\/localhost:7780\/mcp\/review","headers":{"Authorization":"Bearer round-credential"}}}}';

    public function testTheCliStartsWithOnlyTheReviewServerAndItsReadOnlyToolsAllowed(): void
    {
        $command = $this->commandFor([ReviewServerAccess::OPTION => $this->access()]);

        self::assertSame(self::CONNECTION, $this->valueOf('--mcp-config', $command));
        self::assertContains('--strict-mcp-config', $command);
        self::assertSame('mcp__intakt-review__read_file,mcp__intakt-review__list_directory', $this->valueOf('--allowedTools', $command));
    }

    public function testTheCliCallDeniesEveryBuiltInToolAndLoadsNoSettings(): void
    {
        $command = $this->commandFor([ReviewServerAccess::OPTION => $this->access()]);

        self::assertSame('', $this->valueOf('--tools', $command));
        self::assertSame('Bash,BashOutput,KillShell,Read,Edit,MultiEdit,Write,NotebookEdit,Glob,Grep,WebFetch,WebSearch,Task,TodoWrite,SlashCommand,ExitPlanMode', $this->valueOf('--disallowed-tools', $command));
        self::assertSame('', $this->valueOf('--setting-sources', $command));
        self::assertContains('--no-session-persistence', $command);
    }

    public function testThePromptNamesTheReadOnlyToolsInsteadOfSayingThereAreNone(): void
    {
        $prompt = $this->valueOf('--append-system-prompt', $this->commandFor([ReviewServerAccess::OPTION => $this->access()]));

        self::assertStringContainsString('mcp__intakt-review__read_file, mcp__intakt-review__list_directory', $prompt);
        self::assertStringNotContainsString('no tool is available', $prompt);
    }

    public function testACallWithoutTheReviewServerGetsNoMcpConfigurationAndNoAllowedTool(): void
    {
        $command = $this->commandFor([]);

        self::assertNotContains('--mcp-config', $command);
        self::assertNotContains('--allowedTools', $command);
        self::assertContains('--strict-mcp-config', $command);
        self::assertStringContainsString('no tool is available', $this->valueOf('--append-system-prompt', $command));
    }

    public function testAForeignOptionIsDroppedBecauseEachBecomesACliFlag(): void
    {
        self::assertNotContains('--dangerously-skip-permissions', $this->commandFor(['dangerously_skip_permissions' => true, ReviewServerAccess::OPTION => $this->access()]));
    }

    private function access(): ReviewServerAccess
    {
        return new ReviewServerAccess('http://localhost:7780/mcp/review', 'round-credential', ['read_file', 'list_directory']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function commandFor(array $options): array
    {
        $cli = RecordedClaudeCodeCli::replaying('complete');

        new ConfinedClaudeCodeClient($cli)->request(new ClaudeCode('sonnet'), 'Review.', $options);

        return new ModelClient('/bin/sh')->buildCommand('Review.', $cli->options);
    }

    /**
     * @param list<string> $command
     */
    private function valueOf(string $flag, array $command): string
    {
        $at = array_search($flag, $command, true);
        self::assertIsInt($at, $flag.' is missing');

        return $command[$at + 1];
    }
}
