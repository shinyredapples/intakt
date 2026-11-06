<?php

declare(strict_types=1);

namespace Intakt\Upstream\McpParallelToolsCall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Three tools/call of one session run in three processes against one file session store; the interleaving is forced, not hoped for: without the lock a tool waits until all three are inside it.
#[CoversNothing]
final class ParallelToolsCallTest extends TestCase
{
    private const string PINNED = '2025-11-25';

    private const string UNPINNED = '2025-06-18';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mcp-probe-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        touch($this->dir.'/log');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{*,*/*}', GLOB_BRACE) ?: [] as $path) {
            is_file($path) && unlink($path);
        }

        foreach (glob($this->dir.'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            rmdir($folder);
        }

        rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scenarios(): iterable
    {
        yield 'with the session lock' => ['lock', self::UNPINNED];
        yield 'without the session lock' => ['nolock', self::UNPINNED];
        yield 'with the session lock, pinned to 2025-11-25' => ['lock', self::PINNED];
        yield 'without the session lock, pinned to 2025-11-25' => ['nolock', self::PINNED];
    }

    #[DataProvider('scenarios')]
    public function testThreeParallelToolCallsOnOneSessionEachGetOneResponseAndTheirOwnProgress(string $mode, string $version): void
    {
        $headers = $this->openSession($mode, $version);

        $answers = $this->postTogether($mode, $headers, array_map(fn (int $n): array => $this->slowCall($n), [1, 2, 3]));

        foreach ([1, 2, 3] as $index => $n) {
            self::assertSame(200, $answers[$index]['status'], 'call '.$n.' must be served');
            $messages = $this->messages($answers[$index]['body']);
            $results = array_filter($messages, static fn (array $m): bool => isset($m['result']));
            $progress = array_filter($messages, static fn (array $m): bool => 'notifications/progress' === ($m['method'] ?? null));

            self::assertCount(1, $results, 'call '.$n.' must get exactly one response');
            self::assertSame(100 + $n, array_first($results)['id']);
            self::assertSame('done '.(100 + $n), array_first($results)['result']['content'][0]['text']);
            self::assertSame(['tok-'.$n, 'tok-'.$n], array_values(array_map(static fn (array $m): string => $m['params']['progressToken'], $progress)), 'call '.$n.' must get its own progress token twice and no other');
        }
    }

    #[DataProvider('modes')]
    public function testTheSessionLockKeepsTheCallsOfOneSessionApart(string $mode, bool $overlap): void
    {
        $headers = $this->openSession($mode, self::PINNED);
        $this->postTogether($mode, $headers, array_map(fn (int $n): array => $this->slowCall($n), [1, 2, 3]));

        $log = array_map(static fn (string $line): string => explode(' ', $line)[0], file($this->dir.'/log', FILE_IGNORE_NEW_LINES));

        self::assertSame($overlap ? ['enter', 'enter', 'enter'] : ['enter', 'exit', 'enter'], array_slice($log, 0, 3));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'with the lock they run one after the other' => ['lock', false];
        yield 'without the lock they run at the same time' => ['nolock', true];
    }

    public function testAToolWithoutParametersEncodesThemAsAnEmptyObject(): void
    {
        $headers = $this->openSession('lock', self::PINNED);

        $answer = $this->post('lock', $headers, '{"jsonrpc":"2.0","id":7,"method":"tools/list","params":{}}');

        self::assertSame(200, $answer['status']);
        self::assertStringContainsString('"properties":{}', $answer['body'], 'a tool without parameters must list them as {}');
        self::assertStringNotContainsString('"properties":[]', $answer['body']);
    }

    public function testACallWithEmptyArgumentsReachesTheToolAsNoArgumentsThatEncodeAsAnEmptyObject(): void
    {
        $headers = $this->openSession('lock', self::PINNED);

        $answer = $this->post('lock', $headers, $this->slowCall(4, withArguments: '{}')['body']);

        self::assertSame(200, $answer['status']);
        self::assertStringContainsString('done 104', $answer['body']);
        self::assertSame(['104 [] {}'], file($this->dir.'/arguments', FILE_IGNORE_NEW_LINES), 'the tool must get an empty array, and its request must encode it as {}');
    }

    /**
     * @return array<string, string>
     */
    private function openSession(string $mode, string $version): array
    {
        $initialize = sprintf('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"%s","capabilities":{},"clientInfo":{"name":"probe","version":"1.0.0"}}}', $version);
        $opened = $this->post($mode, [], $initialize);
        self::assertNotSame('', $opened['session'], 'initialize must open a session');

        $headers = ['Mcp-Session-Id' => $opened['session']];

        if (self::PINNED === $version) {
            $headers['MCP-Protocol-Version'] = self::PINNED;
        }

        $this->post($mode, $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        return $headers;
    }

    /**
     * @return array{headers?: array<string, string>, body: string}
     */
    private function slowCall(int $n, string $withArguments = ''): array
    {
        $arguments = '' === $withArguments ? '' : ',"arguments":'.$withArguments;
        $body = sprintf('{"jsonrpc":"2.0","id":%d,"method":"tools/call","params":{"name":"slow"%s,"_meta":{"progressToken":"tok-%d"}}}', 100 + $n, $arguments, $n);

        return ['body' => $body];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, session: string, body: string}
     */
    private function post(string $mode, array $headers, string $body): array
    {
        return $this->postTogether($mode, $headers, [['body' => $body]])[0];
    }

    /**
     * @param array<string, string>                    $headers
     * @param list<array{headers?: array<string, string>, body: string}> $requests
     *
     * @return list<array{status: int, session: string, body: string}>
     */
    private function postTogether(string $mode, array $headers, array $requests): array
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        $running = [];

        foreach ($requests as $index => $request) {
            $file = sprintf('%s/request-%d-%s', $this->dir, $index, bin2hex(random_bytes(3)));
            file_put_contents($file, json_encode(['headers' => $headers, 'body' => $request['body']], JSON_THROW_ON_ERROR));
            $command = [PHP_BINARY, __DIR__.'/worker.php', $this->dir, $mode, $file];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $process !== false || throw new RuntimeException('Cannot start the worker.');
            $running[] = [$process, $pipes];
        }

        return array_map($this->finish(...), $running);
    }

    /**
     * @param array{resource, array<int, resource>} $worker
     *
     * @return array{status: int, session: string, body: string}
     */
    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $decoded = json_decode($out, true);
        is_array($decoded) || throw new RuntimeException('The worker gave no answer: '.$error.$out);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(string $body): array
    {
        if (false !== preg_match_all('/^data: (.*)$/m', $body, $matches) && [] !== $matches[1]) {
            return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
        }

        return [json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
}
