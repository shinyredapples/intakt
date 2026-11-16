<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredKey::class)]
final class ConfiguredKeyTest extends TestCase
{
    public function testAConfiguredStateGivenAWholeKeyKeepsOnlyItsLastFourCharacters(): void
    {
        $configured = new ConfiguredKey(KeyKind::HuggingFace, 'hf_'.str_repeat('0', 20).'wxyz');

        self::assertSame('wxyz', $configured->lastCharacters);
    }

    public function testAConfiguredStateGivenAWholeKeyDoesNotShowItInJson(): void
    {
        $configured = new ConfiguredKey(KeyKind::HuggingFace, 'hf_hiddenpart'.str_repeat('0', 20));

        self::assertStringNotContainsString('hiddenpart', (string) json_encode($configured));
    }

    public function testAConfiguredStateKeepsFewerThanFourCharactersAsGiven(): void
    {
        self::assertSame('gh', new ConfiguredKey(KeyKind::HuggingFace, 'gh')->lastCharacters);
    }
}
