<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Domain\BundleHeader;

final readonly class BundleFile
{
    // Far above a real header (one line per prerequisite and per ref), so a file that is no bundle is never read whole.
    public const int HEADER_LIMIT = 1048576;

    // The pack's signature, version and object count follow the empty line that ends the header.
    private const int PACK_HEADER = 12;

    public function headerOf(string $path): ?BundleHeader
    {
        // One byte more than the limit and the empty line after it, so a header of exactly the limit still shows its end.
        $start = $this->start($path, self::HEADER_LIMIT + 1);
        $end = null === $start ? false : strpos($start, "\n\n");

        return false === $end || null === $start ? null : BundleHeader::parse(substr($start, 0, $end));
    }

    // The count the pack's own header states, because the objects unbundling appends to a thin pack come after them.
    public function packedObjectCount(string $path): ?int
    {
        $start = $this->start($path, self::HEADER_LIMIT + 2 + self::PACK_HEADER);
        $end = null === $start ? false : strpos($start, "\n\n");
        $pack = false === $end || null === $start ? '' : substr($start, $end + 2, self::PACK_HEADER);

        if (self::PACK_HEADER !== strlen($pack) || !str_starts_with($pack, 'PACK')) {
            return null;
        }

        $count = unpack('N', $pack, 8);

        return false === $count ? null : (int) $count[1];
    }

    private function start(string $path, int $length): ?string
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;

        if (false === $handle) {
            return null;
        }

        try {
            $start = stream_get_contents($handle, $length);
        } finally {
            fclose($handle);
        }

        return false === $start ? null : $start;
    }
}
