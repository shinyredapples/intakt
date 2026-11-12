<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Domain\BundleHeader;

final readonly class BundleFile
{
    // Far above a real header (one line per prerequisite and per ref), so a file that is no bundle is never read whole.
    public const int HEADER_LIMIT = 1048576;

    public function headerOf(string $path): ?BundleHeader
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;

        if (false === $handle) {
            return null;
        }

        try {
            // One byte more than the limit and the empty line after it, so a header of exactly the limit still shows its end.
            $start = stream_get_contents($handle, self::HEADER_LIMIT + 1);
        } finally {
            fclose($handle);
        }

        $end = false === $start ? false : strpos($start, "\n\n");

        return false === $end ? null : BundleHeader::parse(substr($start, 0, $end));
    }
}
