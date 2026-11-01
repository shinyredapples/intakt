<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ClaudeCodePlatformFactory;
use Intakt\Core\Ai\Infrastructure\ConfinedClaudeCodeClient;
use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ClaudeCodePlatformFactory::class)]
#[UsesClass(ConfinedClaudeCodeClient::class)]
#[UsesClass(FinishedProcessResult::class)]
#[UsesClass(ProjectModelCatalog::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Kernel::class)]
final class ClaudeCodePlatformFactoryTest extends KernelTestCase
{
    public function testATruncatedRecordedStreamMakesTheBridgeRaiseIncompleteStream(): void
    {
        $platform = $this->claudeCodePlatform(RecordedClaudeCodeCli::replaying('truncated'));

        $result = $platform->invoke('sonnet', 'Review.', ['stream' => true])->getResult();

        self::assertInstanceOf(StreamResult::class, $result);
        $this->expectException(IncompleteStreamException::class);
        iterator_to_array($result->getContent());
    }

    public function testACompleteRecordedStreamIsReadToTheEndByTheBridge(): void
    {
        $platform = $this->claudeCodePlatform(RecordedClaudeCodeCli::replaying('complete'));

        $result = $platform->invoke('sonnet', 'Review.', ['stream' => true])->getResult();

        self::assertInstanceOf(StreamResult::class, $result);
        $text = '';
        foreach ($result->getContent() as $delta) {
            self::assertInstanceOf(TextDelta::class, $delta);
            $text .= $delta->getText();
        }
        self::assertStringContainsString('"verdict"', $text);
    }

    private function claudeCodePlatform(RecordedClaudeCodeCli $cli): PlatformInterface
    {
        $models = new ProjectModelCatalog(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'sonnet']]]])->models(Provider::ClaudeCode);

        return new ClaudeCodePlatformFactory(ScriptedReviewer::in(self::getContainer())->dispatcher)->create($cli, $models);
    }
}
