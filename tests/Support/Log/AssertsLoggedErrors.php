<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Assert;

trait AssertsLoggedErrors
{
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
