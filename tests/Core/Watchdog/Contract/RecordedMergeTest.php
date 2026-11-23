<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedMerge::class)]
#[CoversClass(CommitName::class)]
#[CoversClass(CommitNameNotFull::class)]
final class RecordedMergeTest extends TestCase
{
    private const string SHA1 = '0123456789abcdef0123456789abcdef01234567';
    private const string SHA256 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testARecordedMergeKeepsAFullSha1HeadAndBase(): void
    {
        $merge = new RecordedMerge(self::SHA1, self::SHA1);

        self::assertSame([self::SHA1, self::SHA1], [$merge->head, $merge->base]);
    }

    public function testARecordedMergeKeepsFullSha256Names(): void
    {
        $merge = new RecordedMerge(self::SHA256, self::SHA256);

        self::assertSame([self::SHA256, self::SHA256], [$merge->head, $merge->base]);
    }

    public function testARecordedMergeRefusesAnAbbreviatedHead(): void
    {
        $this->expectExceptionObject(new CommitNameNotFull('The recorded merge head "0123456" is not a full commit name.'));

        new RecordedMerge('0123456', self::SHA1);
    }

    public function testARecordedMergeRefusesABaseThatIsNotAFullCommitName(): void
    {
        $this->expectExceptionObject(new CommitNameNotFull('The recorded merge base "main" is not a full commit name.'));

        new RecordedMerge(self::SHA1, 'main');
    }

    public function testARecordedMergeRefusesAnUppercaseHead(): void
    {
        $this->expectException(CommitNameNotFull::class);

        new RecordedMerge(strtoupper(self::SHA1), self::SHA1);
    }

    public function testARecordedMergeRefusesAHeadOneCharacterTooLong(): void
    {
        $this->expectException(CommitNameNotFull::class);

        new RecordedMerge(self::SHA1.'0', self::SHA1);
    }

    public function testARecordedMergeRefusesAHeadWithATrailingNewline(): void
    {
        $this->expectException(CommitNameNotFull::class);

        new RecordedMerge(self::SHA1."\n", self::SHA1);
    }
}
