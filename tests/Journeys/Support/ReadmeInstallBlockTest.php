<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadmeInstallBlock::class)]
final class ReadmeInstallBlockTest extends TestCase
{
    public function testTheCommandsUnderTheMarkerAreReadAndAnEarlierUnmarkedBlockIsIgnored(): void
    {
        $readme = "```\ngit clone --branch v1 x\ndocker compose up -d --wait\n```\n\n<!-- install-check: the block below -->\n```\ngit clone x && cd intakt\n\n# a note\ndocker compose up -d --wait\n```\n";

        self::assertSame(['git clone x && cd intakt', 'docker compose up -d --wait'], ReadmeInstallBlock::commandsIn($readme));
    }

    public function testAReadmeWithoutTheMarkerCannotBeChecked(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        ReadmeInstallBlock::commandsIn("```\ndocker compose up -d --wait\n```\n");
    }

    public function testAMarkerNotFollowedByACodeBlockCannotBeChecked(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        ReadmeInstallBlock::commandsIn("<!-- install-check -->\nSome words.\n```\nls\n```\n");
    }

    public function testABlockThatIsNeverClosedCannotBeChecked(): void
    {
        $this->expectException(InstallCheckCouldNotRun::class);

        ReadmeInstallBlock::commandsIn("<!-- install-check -->\n```\nls\n");
    }
}
