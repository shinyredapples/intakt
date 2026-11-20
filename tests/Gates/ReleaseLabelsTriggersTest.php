<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class ReleaseLabelsTriggersTest extends TestCase
{
    public function testOnlyOpeningReopeningOrRetitlingAPullRequestLabelsIt(): void
    {
        /** @var array{on?: array<string, mixed>, jobs: array{label: array{if: string}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');

        self::assertSame(['pull_request' => ['types' => ['opened', 'reopened', 'edited']]], $workflow['on'] ?? null);
        self::assertStringContainsString("&& (github.event.action != 'edited' || github.event.changes.title)", $workflow['jobs']['label']['if']);
    }

    public function testAnEditThatSkipsTheLabelJobCannotCancelARunningOne(): void
    {
        /** @var array{concurrency?: mixed, jobs: array{label: array{concurrency?: mixed}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');

        self::assertArrayNotHasKey('concurrency', $workflow);
        self::assertSame(['group' => 'release-labels-${{ github.event.pull_request.number }}', 'cancel-in-progress' => true], $workflow['jobs']['label']['concurrency'] ?? null);
    }
}
