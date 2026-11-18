<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class ReleaseLabelsTriggersTest extends TestCase
{
    public function testAPullRequestEditTriggersNoReleaseLabelsRun(): void
    {
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-labels.yml');

        self::assertSame(['opened', 'reopened', 'synchronize'], $workflow['on']['pull_request']['types']);
    }
}
