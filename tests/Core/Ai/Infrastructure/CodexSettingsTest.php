<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodexSettings::class)]
#[CoversClass(CodexEffort::class)]
final class CodexSettingsTest extends TestCase
{
    public function testTheConfiguredModelAndEffortBecomeTheCliOptions(): void
    {
        $settings = CodexSettings::fromConfiguration(['model' => 'gpt-5.3-codex', 'effort' => 'xhigh']);

        self::assertSame(['model' => 'gpt-5.3-codex', 'config' => ['model_reasoning_effort="xhigh"']], $settings->options());
    }
}
