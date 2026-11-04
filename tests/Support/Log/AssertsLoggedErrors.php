<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Assert;

trait AssertsLoggedErrors
{
    // The extension can only warn, so the test itself fails here; taking the records keeps it from warning a second time.
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $unasserted = LogRecords::ofThisRun()?->takeErrors() ?? [];
        Assert::assertSame(
            [],
            array_map(static fn (array $record): string => sprintf('Unasserted %s log record: "%s"', $record['level'], $record['message']), $unasserted),
            'The test ended with log records nobody asserted.',
        );
    }

    // Removes the record, so the extension does not fail the test for it.
    private static function assertLoggedError(string $level, string $message): void
    {
        $records = LogRecords::ofThisRun();
        Assert::assertNotNull($records, 'No test log records: run the tests with the UnassertedErrorLogExtension.');
        Assert::assertTrue($records->remove($level, $message), sprintf('Expected a %s log record "%s", none was logged.', $level, $message));
    }

    private static function assertLoggedErrors(int $times, string $message): void
    {
        for ($logged = 0; $logged < $times; ++$logged) {
            self::assertLoggedError('error', $message);
        }
    }
}
