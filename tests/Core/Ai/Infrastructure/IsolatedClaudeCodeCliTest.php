<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Infrastructure\IsolatedClaudeCodeCli;
use Intakt\Core\Ai\Infrastructure\RemovedAfterRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\Exception\CliNotFoundException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(IsolatedClaudeCodeCli::class)]
#[CoversClass(RemovedAfterRun::class)]
#[CoversClass(ReviewServerAccess::class)]
final class IsolatedClaudeCodeCliTest extends TestCase
{
    private const string INHERITED = 'INTAKT_TEST_INHERITED_CREDENTIAL';

    private const string LATE = 'INTAKT_TEST_LATE_CREDENTIAL';

    private const string FAKE_CLI = <<<'PHP_WRAP'
    #!/usr/bin/env php
    <?php
    $at = array_search('--mcp-config', $argv, true);
    $configuration = false === $at ? null : ['file' => $argv[$at + 1], 'mode' => fileperms($argv[$at + 1]) & 0o777, 'text' => file_get_contents($argv[$at + 1])];
    echo json_encode(['type' => 'result', 'result' => json_encode(['cwd' => getcwd(), 'environment' => getenv(), 'arguments' => array_slice($argv, 1), 'configuration' => $configuration])]), PHP_EOL;

    PHP_WRAP;

    private const string BEARER = 'round-credential';

    private string $root;

    private string $path;

    public function testTheCliRunsInItsOwnDirectoryAndNeverInIntaktsOrACheckout(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'));

        self::assertSame($this->root.'/home', $seen['cwd']);
    }

    public function testTheCliGetsNoValueOfIntaktsEnvironmentButItsPath(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'));

        self::assertSame(['CLAUDE_CODE_OAUTH_TOKEN', 'HOME', 'PATH'], $this->sortedNamesWithAValue($seen['environment']));
        self::assertSame(['', $this->root.'/bin:'.$this->path], [$seen['environment'][self::INHERITED], $seen['environment']['PATH']]);
    }

    public function testAVariableAddedAfterTheCliWasBuiltReachesItEmpty(): void
    {
        $cli = new IsolatedClaudeCodeCli($this->root.'/home', 'login');
        putenv(self::LATE.'=late');
        $_ENV[self::LATE] = 'late';

        $seen = $this->runOf($cli);

        self::assertSame('', $seen['environment'][self::LATE]);
    }

    public function testTheCliLogsInWithTheSubscriptionTokenAndKeepsItsStateInItsDirectory(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'));

        self::assertSame(['login', $this->root.'/home'], [$seen['environment']['CLAUDE_CODE_OAUTH_TOKEN'], $seen['environment']['HOME']]);
    }

    public function testTheRoundsBearerNeverReachesTheCliCommandLineWhichATimeoutWouldLog(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'), [ReviewServerAccess::OPTION => $this->access()]);

        self::assertStringNotContainsString(self::BEARER, implode(' ', $seen['arguments']));
    }

    public function testTheCliReadsTheReviewServerFromAFileInItsOwnDirectoryThatOnlyItsOwnerCanRead(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'), [ReviewServerAccess::OPTION => $this->access()]);

        self::assertNotNull($seen['configuration']);
        self::assertSame([$this->root.'/home', 0o600], [dirname($seen['configuration']['file']), $seen['configuration']['mode']]);
        self::assertSame(['mcpServers' => ['intakt-review' => ['type' => 'http', 'url' => 'http://localhost:7780/mcp/review', 'headers' => ['Authorization' => 'Bearer '.self::BEARER]]]], json_decode($seen['configuration']['text'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTheConfigurationFileIsRemovedOnceTheRunWasRead(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'), [ReviewServerAccess::OPTION => $this->access()]);

        self::assertNotNull($seen['configuration']);
        self::assertFileDoesNotExist($seen['configuration']['file']);
    }

    public function testTheConfigurationFileIsRemovedOnceTheStreamOfTheRunWasRead(): void
    {
        $result = new IsolatedClaudeCodeCli($this->root.'/home', 'login')->request(new ClaudeCode('sonnet'), 'Review.', [ReviewServerAccess::OPTION => $this->access()]);

        iterator_to_array($result->getDataStream());

        self::assertSame([], glob($this->root.'/home/review-server-*'));
    }

    public function testTheConfigurationFileIsRemovedWhenTheCliCannotStart(): void
    {
        putenv('PATH='.$this->root.'/empty');

        try {
            new IsolatedClaudeCodeCli($this->root.'/home', 'login')->request(new ClaudeCode('sonnet'), 'Review.', [ReviewServerAccess::OPTION => $this->access()]);
            self::fail('The CLI started although it is not on the PATH.');
        } catch (CliNotFoundException) {
        }

        self::assertSame([], glob($this->root.'/home/review-server-*'));
    }

    public function testWithoutAReviewServerTheCliIsGivenNoMcpConfiguration(): void
    {
        $seen = $this->runOf(new IsolatedClaudeCodeCli($this->root.'/home', 'login'));

        self::assertNull($seen['configuration']);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-claude-code-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/bin/claude', self::FAKE_CLI);
        chmod($this->root.'/bin/claude', 0o700);
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->root.'/bin:'.$this->path);
        putenv(self::INHERITED.'=inherited');
        $_ENV[self::INHERITED] = 'inherited';
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        putenv(self::INHERITED);
        putenv(self::LATE);
        unset($_ENV[self::INHERITED], $_ENV[self::LATE]);
        new Filesystem()->remove($this->root);
    }

    private function access(): ReviewServerAccess
    {
        return new ReviewServerAccess('intakt-review', 'http://localhost:7780/mcp/review', self::BEARER, ['read_file']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{cwd: string, environment: array<string, string>, arguments: list<string>, configuration: array{file: string, mode: int, text: string}|null}
     */
    private function runOf(IsolatedClaudeCodeCli $cli, array $options = []): array
    {
        $result = $cli->request(new ClaudeCode('sonnet'), 'Review.', $options)->getData()['result'];
        self::assertIsString($result);

        /** @var array{cwd: string, environment: array<string, string>, arguments: list<string>, configuration: array{file: string, mode: int, text: string}|null} $seen */
        $seen = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        return $seen;
    }

    /**
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    private function sortedNamesWithAValue(array $environment): array
    {
        $names = array_keys(array_filter($environment, static fn (string $value): bool => '' !== $value));
        sort($names);

        return $names;
    }
}
