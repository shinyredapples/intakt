<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Infrastructure\ConfinedClaudeCodeClient;
use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Core\Ai\Infrastructure\McpConfigFile;
use Intakt\Core\Ai\Infrastructure\ReviewServerCall;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(ConfinedClaudeCodeClient::class)]
#[CoversClass(McpConfigFile::class)]
#[UsesClass(ReviewServerAccess::class)]
#[UsesClass(FinishedProcessResult::class)]
#[UsesClass(ReviewServerCall::class)]
final class ConfinedClaudeCodeClientTest extends TestCase
{
    private const string CONNECTION = '{"mcpServers":{"intakt-review":{"type":"http","url":"http:\/\/localhost:7780\/mcp\/review","headers":{"Authorization":"Bearer round-credential"}}}}';

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/intakt-confined-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->home);
    }

    public function testTheCliStartsWithOnlyTheReviewServerAndItsReadOnlyToolsAllowed(): void
    {
        $cli = RecordedClaudeCodeCli::replaying('complete');
        $command = $this->commandFor([ReviewServerAccess::OPTION => $this->access()], $cli);

        self::assertSame(self::CONNECTION, $cli->mcpConfiguration);
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

    public function testTheCredentialNeverReachesTheCliCommandLine(): void
    {
        $command = $this->commandFor([ReviewServerAccess::OPTION => $this->access()]);

        self::assertStringNotContainsString('round-credential', implode(' ', $command));
        self::assertStringStartsWith($this->home.'/mcp-', $this->valueOf('--mcp-config', $command));
    }

    public function testATimedOutCliCallDoesNotRepeatTheCredentialInItsFailure(): void
    {
        $process = new Process(['sh', '-c', 'sleep 5', ...$this->commandFor([ReviewServerAccess::OPTION => $this->access()])], timeout: 0.2);

        $this->expectException(ProcessTimedOutException::class);
        $this->expectExceptionMessageMatches('/^(?!.*round-credential)/s');

        $process->run();
    }

    public function testOnlyItsOwnerCanReadTheConfigurationWhileTheCallRuns(): void
    {
        $cli = RecordedClaudeCodeCli::replaying('complete');

        $this->commandFor([ReviewServerAccess::OPTION => $this->access()], $cli);

        self::assertSame(0o600, $cli->mcpConfigurationMode);
    }

    public function testTheConfigurationIsRemovedOnceTheCallHasFinished(): void
    {
        $cli = RecordedClaudeCodeCli::replaying('complete');
        $call = new ConfinedClaudeCodeClient($cli, $this->home)->request(new ClaudeCode('sonnet'), 'Review.', [ReviewServerAccess::OPTION => $this->access()]);

        $call->getData();

        self::assertFileDoesNotExist($this->configurationOf($cli));
    }

    public function testTheConfigurationIsRemovedWhenTheCliCannotStart(): void
    {
        $failing = self::createStub(ModelClientInterface::class);
        $failing->method('request')->willThrowException(new RuntimeException('The CLI cannot start.'));

        try {
            new ConfinedClaudeCodeClient($failing, $this->home)->request(new ClaudeCode('sonnet'), 'Review.', [ReviewServerAccess::OPTION => $this->access()]);
        } catch (RuntimeException) {
        }

        self::assertSame([], glob($this->home.'/mcp-*'));
    }

    public function testThePromptNamesTheReadOnlyToolsAndTheirFencedAnswers(): void
    {
        $prompt = $this->valueOf('--append-system-prompt', $this->commandFor([ReviewServerAccess::OPTION => $this->access()]));

        self::assertStringContainsString('mcp__intakt-review__read_file, mcp__intakt-review__list_directory', $prompt);
        self::assertStringContainsString('each answer is fenced repository text', $prompt);
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
    private function commandFor(array $options, ?RecordedClaudeCodeCli $cli = null): array
    {
        $cli ??= RecordedClaudeCodeCli::replaying('complete');
        new ConfinedClaudeCodeClient($cli, $this->home)->request(new ClaudeCode('sonnet'), 'Review.', $options);

        return array_values(new ModelClient('/bin/sh')->buildCommand('Review.', $cli->options));
    }

    private function configurationOf(RecordedClaudeCodeCli $cli): string
    {
        $path = $cli->options['mcp_config'] ?? null;
        self::assertIsString($path);

        return $path;
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
