<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Mcp\Contract\OwnerToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnerToken::class)]
final class OwnerTokenTest extends TestCase
{
    private ?string $file = null;

    public function testAConfiguredTokenWinsOverTheKeptFile(): void
    {
        $this->keep(str_repeat('ab', 32));

        self::assertSame('chosen', new OwnerToken('chosen', $this->file)->value());
    }

    public function testAnEmptyVariableReadsTheTokenFromTheKeptFile(): void
    {
        $this->keep(str_repeat('ab', 32)."\n");

        self::assertSame(str_repeat('ab', 32), new OwnerToken('', $this->file)->value());
    }

    public function testNoKeptFileGivesNoToken(): void
    {
        self::assertSame('', new OwnerToken('', null)->value());
        self::assertSame('', new OwnerToken('', sys_get_temp_dir().'/missing-'.uniqid('', true))->value());
    }

    public function testAKeptFileThatHoldsNoSixtyFourHexTokenGivesNoToken(): void
    {
        $this->keep('not-a-token');

        self::assertSame('', new OwnerToken('', $this->file)->value());
    }

    public function testAKeptFileThatCannotBeReadGivesNoTokenWithoutAWarning(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }

        $this->keep(str_repeat('ab', 32));
        chmod((string) $this->file, 0o000);

        try {
            self::assertSame('', new OwnerToken('', $this->file)->value());
        } finally {
            chmod((string) $this->file, 0o600);
        }
    }

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function keep(string $content): void
    {
        $this->file = sys_get_temp_dir().'/'.uniqid('owner-token-', true);
        file_put_contents($this->file, $content);
    }
}
