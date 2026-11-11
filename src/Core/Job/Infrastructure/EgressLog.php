<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// Squid's native access log: time, elapsed, client, code/status, bytes, method, target, user, hierarchy/peer, type.
final readonly class EgressLog
{
    // The last lines only, because the job decides how much the proxy logs and the orchestrator holds the log in memory.
    public const int LINES = 1000;

    // At most this many distinct refusals reach the ledger, so a job cannot flood it with one event per refused request.
    public const int MAX_REFUSALS = 20;

    private const int MAX_TARGET_BYTES = 255;

    // Printable ASCII targets only, so what the job asked for can always be stored as JSON.
    private const string REFUSAL = '#^\d+\.\d{3} +-?\d+ +\S+ +TCP_DENIED/\d{3} +\d+ +([A-Z]+) +([\x21-\x7E]+) #m';

    /**
     * @return list<EgressRefusal>
     */
    public function refusalsIn(string $log): array
    {
        preg_match_all(self::REFUSAL, $log, $lines, PREG_SET_ORDER);
        $refusals = [];

        foreach ($lines as [, $method, $target]) {
            $refusals[$method.' '.$target] ??= new EgressRefusal($method, substr($target, 0, self::MAX_TARGET_BYTES));
        }

        return array_slice(array_values($refusals), 0, self::MAX_REFUSALS);
    }
}
