<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Mcp\Contract\OwnerClientLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(OwnerClientLine::class)]
final class OwnerClientLineTest extends TestCase
{
    public function testAPlainTokenKeepsTheDoubleQuotedHeader(): void
    {
        $line = new OwnerClientLine('http://localhost:7781', 'demo-owner-token')->line();

        self::assertSame('claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer demo-owner-token"', $line);
    }

    public function testATokenWithShellCharactersIsSingleQuoted(): void
    {
        $line = new OwnerClientLine('http://localhost:7781', 'demo$(printf changed)-token')->line();

        self::assertSame("claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header 'Authorization: Bearer demo\$(printf changed)-token'", $line);
    }

    #[DataProvider('pastedArguments')]
    public function testPastingTheLineHandsTheClientTheLiteralAddressAndToken(string $baseUrl, string $token): void
    {
        $line = new OwnerClientLine($baseUrl, $token)->line();

        $shell = new Process(['sh', '-c', 'claude() { printf "%s\n" "$@"; }; '.$line]);
        $shell->mustRun();

        self::assertSame(
            ['mcp', 'add', '--transport', 'http', 'intakt', rtrim($baseUrl, '/').'/mcp/owner', '--header', 'Authorization: Bearer '.$token, ''],
            explode("\n", $shell->getOutput()),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pastedArguments(): iterable
    {
        yield 'a plain token' => ['http://localhost:7781', 'demo-owner-token'];
        yield 'a command substitution' => ['http://localhost:7781', 'demo$(printf changed)-token'];
        yield 'backticks and a variable' => ['http://localhost:7781', 'demo`printf changed`$HOME'];
        yield 'a single quote' => ['http://localhost:7781', "demo'; printf changed; '"];
        yield 'a double quote and a glob' => ['http://localhost:7781', 'demo"*?[a]'];
        yield 'an address with shell characters' => ['http://localhost:7781/$(printf changed);x&y', 'demo-owner-token'];
        yield 'an address with a trailing slash' => ['http://localhost:7781/', 'demo-owner-token'];
    }

    public function testWithoutATokenTheLineHasNoHeader(): void
    {
        self::assertSame(
            'claude mcp add --transport http intakt http://localhost:7781/mcp/owner',
            new OwnerClientLine('http://localhost:7781/', '')->line(),
        );
    }
}
