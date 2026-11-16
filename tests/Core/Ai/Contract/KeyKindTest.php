<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\KeyKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyKind::class)]
final class KeyKindTest extends TestCase
{
    public function testAnAnthropicKeyIsNotTakenForAnOpenAiKey(): void
    {
        self::assertSame(KeyKind::Anthropic, KeyKind::fromForm('sk-ant-'.str_repeat('0', 24)));
    }

    public function testATruncatedAnthropicKeyIsNotTakenForAnOpenAiKey(): void
    {
        self::assertSame([null, null], [KeyKind::fromForm('sk-ant-abcd'), KeyKind::fromForm('sk-ant-'.str_repeat('0', 5))]);
    }

    public function testAKeyWithOnlyAPrefixIsNotRecognised(): void
    {
        self::assertSame([null, null, null], [KeyKind::fromForm('sk-ant-'), KeyKind::fromForm('sk-'), KeyKind::fromForm('hf_')]);
    }

    public function testAKeyWithATrailingNewlineIsNotRecognised(): void
    {
        self::assertNull(KeyKind::fromForm('hf_'.str_repeat('0', 20)."\n"));
    }

    public function testAHuggingFaceTokenWithADashIsNotRecognised(): void
    {
        self::assertNull(KeyKind::fromForm('hf_'.str_repeat('0', 10).'-'.str_repeat('0', 10)));
    }
}
