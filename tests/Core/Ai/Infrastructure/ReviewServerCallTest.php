<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Core\Ai\Infrastructure\McpConfigFile;
use Intakt\Core\Ai\Infrastructure\ReviewServerCall;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(ReviewServerCall::class)]
#[UsesClass(McpConfigFile::class)]
#[UsesClass(FinishedProcessResult::class)]
final class ReviewServerCallTest extends TestCase
{
    private const array SERVER_TOOLS = ['mcp__intakt-review__read_file', 'mcp__intakt-review__list_directory'];

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/intakt-server-call-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->home);
    }

    public function testEachServerToolAnswerIsReadBackFromTheStreamWhetherTextOrTextBlocks(): void
    {
        $answers = $this->callReplaying('server-read')->reviewServerAnswers();

        self::assertSame([
            "<<<UNTRUSTED tool result fence-764b20a27f459215>>>\nfinal class Sample\n{\n}\n\n<<<END fence-764b20a27f459215>>>",
            "<<<UNTRUSTED tool result fence-ba2aa86defc6af73>>>\n\"missing.php\" is not a file.\n<<<END fence-ba2aa86defc6af73>>>",
        ], $answers);
    }

    public function testABuiltInToolTheCliRefusedIsNoServerAnswer(): void
    {
        self::assertSame([], $this->callReplaying('tool-use')->reviewServerAnswers());
    }

    public function testAServerToolCallWithoutAnAnswerGivesAnEmptyOne(): void
    {
        self::assertSame([''], $this->callReplaying('server-read-unanswered')->reviewServerAnswers());
    }

    public function testTheConfigurationIsRemovedWhenTheResultIsDroppedUnread(): void
    {
        $call = $this->callReplaying('complete');
        $configuration = glob($this->home.'/mcp-*');
        self::assertCount(1, (array) $configuration);

        unset($call);

        self::assertSame([], glob($this->home.'/mcp-*'));
    }

    private function callReplaying(string $recording): ReviewServerCall
    {
        $call = RecordedClaudeCodeCli::replaying($recording)->request(new ClaudeCode('sonnet'), 'Review.');
        $result = new ReviewServerCall(new FinishedProcessResult($call), McpConfigFile::in($this->home, ['mcpServers' => []]), self::SERVER_TOOLS);
        $process = $result->getObject();
        self::assertInstanceOf(Process::class, $process);
        $process->wait();

        return $result;
    }
}
