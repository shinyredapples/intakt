<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\IsolatedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(IsolatedClaudeCodeCli::class)]
final class IsolatedClaudeCodeCliTest extends TestCase
{
    private const string INHERITED = 'INTAKT_TEST_INHERITED_CREDENTIAL';

    private const string LATE = 'INTAKT_TEST_LATE_CREDENTIAL';

    private const string FAKE_CLI = <<<'PHP_WRAP'
    #!/usr/bin/env php
    <?php echo json_encode(['type' => 'result', 'result' => json_encode(['cwd' => getcwd(), 'environment' => getenv()])]), PHP_EOL;

    PHP_WRAP;

    private string $root;

    private string $path;

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

    /**
     * @return array{cwd: string, environment: array<string, string>}
     */
    private function runOf(IsolatedClaudeCodeCli $cli): array
    {
        $result = $cli->request(new ClaudeCode('sonnet'), 'Review.')->getData()['result'];
        self::assertIsString($result);

        /** @var array{cwd: string, environment: array<string, string>} $seen */
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
