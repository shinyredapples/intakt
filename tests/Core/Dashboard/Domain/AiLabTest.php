<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\AiLab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AiLab::class)]
final class AiLabTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?AiLab}>
     */
    public static function models(): iterable
    {
        yield 'hosted Kimi' => ['moonshotai/Kimi-K3.1-Pro:deepinfra', 'Kimi K3.1 Pro', AiLab::MoonshotAi];
        yield 'hosted DeepSeek' => ['deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra', 'DeepSeek V4 Pro 0813', AiLab::DeepSeek];
        yield 'Claude with a dashed version' => ['claude-opus-4-5', 'Claude Opus 4.5', AiLab::Anthropic];
        yield 'a Claude Code model name' => ['opus', 'Opus', AiLab::Anthropic];
        yield 'GPT under a namespace' => ['openai/gpt-6.2-sol', 'GPT 6.2 Sol', AiLab::OpenAi];
        yield 'Qwen under a namespace' => ['Qwen/Qwen3-Coder-480B', 'Qwen3 Coder 480B', AiLab::Qwen];
        yield 'an unknown lab' => ['acme/robot-7', 'Robot 7', null];
        yield 'an unknown name that starts like a family' => ['acme/gptish', 'Gptish', null];
        yield 'a bare lab namespace' => ['anthropic/', '', AiLab::Anthropic];
    }

    #[DataProvider('models')]
    public function testAModelIdReadsAsItsNameWithoutNamespaceOrHost(string $id, string $name, ?AiLab $lab): void
    {
        self::assertSame($name, AiLab::modelName($id));
    }

    #[DataProvider('models')]
    public function testAModelIdIsMatchedToItsAiLabOnlyWhenTheLabIsKnown(string $id, string $name, ?AiLab $lab): void
    {
        self::assertSame($lab, AiLab::of($id));
    }
}
