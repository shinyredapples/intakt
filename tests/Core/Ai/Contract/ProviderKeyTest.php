<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ProviderKey::class)]
#[UsesClass(KeyKind::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(ConfiguredKey::class)]
final class ProviderKeyTest extends TestCase
{
    private function body(string $tail = '1234'): string
    {
        return str_repeat('0', 24).$tail;
    }

    public function testAnAnthropicKeyIsRecognisedAsAnthropic(): void
    {
        self::assertSame(KeyKind::Anthropic, ProviderKey::recognise('sk-ant-'.$this->body())->kind());
    }

    public function testAnOpenAiKeyIsRecognisedAsOpenAi(): void
    {
        self::assertSame(KeyKind::OpenAi, ProviderKey::recognise('sk-'.$this->body())->kind());
    }

    public function testAnOpenAiProjectKeyIsRecognisedAsOpenAi(): void
    {
        self::assertSame(KeyKind::OpenAi, ProviderKey::recognise('sk-proj-'.$this->body())->kind());
    }

    public function testAHuggingFaceTokenIsRecognisedAsHuggingFace(): void
    {
        self::assertSame(KeyKind::HuggingFace, ProviderKey::recognise('hf_'.$this->body())->kind());
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame(KeyKind::HuggingFace, ProviderKey::recognise('  hf_'.$this->body()."\n")->kind());
    }

    public function testAKeyOfAnUnknownFormIsRefused(): void
    {
        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise('xx-'.$this->body());
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise('   ');
    }

    public function testTheRefusalMessageDoesNotContainTheKey(): void
    {
        $key = 'xx-secretvalue'.$this->body();

        try {
            ProviderKey::recognise($key);
            self::fail('The key should be refused.');
        } catch (KeyNotRecognised $refusal) {
            self::assertStringNotContainsString('secretvalue', $refusal->getMessage());
        }
    }

    public function testTheConfiguredStateShowsOnlyTheLastFourCharacters(): void
    {
        $configured = ProviderKey::recognise('sk-ant-'.$this->body())->configured();

        self::assertEquals(new ConfiguredKey(KeyKind::Anthropic, '1234'), $configured);
    }

    public function testAShortKeyShowsFewerThanFourCharacters(): void
    {
        $configured = ProviderKey::recognise('hf_abcdefgh')->configured();

        self::assertSame('gh', $configured->lastCharacters);
    }

    public function testTheKeyDoesNotAppearInVarExportPrintROrVarDumpOutput(): void
    {
        $key = ProviderKey::recognise('sk-ant-'.$this->body('visiblepart'));

        ob_start();
        var_dump($key);
        print_r($key);
        $output = ob_get_clean().var_export($key, true);

        self::assertStringNotContainsString('visiblepart', $output);
    }

    public function testTheKeyDoesNotAppearInATraceThatReceivedIt(): void
    {
        $caught = null;

        try {
            ProviderKey::recognise('xx-tracedvalue'.$this->body());
        } catch (Throwable $caught) {
        }

        self::assertStringNotContainsString('tracedvalue', (string) $caught?->getTraceAsString());
    }
}
