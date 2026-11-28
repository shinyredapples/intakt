<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;

// One home for the hash a settled finding is stored with and compared against, because the write and the briefing must hash the same way; the whole file, because a finding's symbol cannot be cut out of every language reliably.
final readonly class SettledCode
{
    public const string WHOLE_FILE = '';

    private const string ALGORITHM = 'sha256';

    public function inRoot(string $checkoutRoot, string $file): ?CodeSnapshot
    {
        try {
            $checkout = new Checkout($checkoutRoot);
        } catch (CheckoutNotADirectory) {
            // Nothing, because a checkout removed after the judge leaves no code to hash.
            return null;
        }

        return $this->in($checkout, $file);
    }

    // Nothing for a deleted file, a link or a path outside the checkout, because a hash of another file would settle code nobody judged.
    public function in(Checkout $checkout, string $file): ?CodeSnapshot
    {
        $content = CheckedOutFile::contentBehindNoLinkOf($checkout, $file);

        return null === $content ? null : new CodeSnapshot($file, self::WHOLE_FILE, hash(self::ALGORITHM, $content));
    }

    public function recorded(string $file, string $hash): CodeSnapshot
    {
        return new CodeSnapshot($file, self::WHOLE_FILE, $hash);
    }
}
