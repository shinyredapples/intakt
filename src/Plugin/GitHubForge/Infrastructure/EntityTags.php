<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// An `ETag` is sent back as `If-None-Match`, where a `*` or a bare token would match whatever the page holds now, so only an RFC 9110 entity tag is taken.
final readonly class EntityTags
{
    private const string ENTITY_TAG = '~\A(?:W/)?"[\x21\x23-\x7E\x80-\xFF]*"\z~';

    /**
     * @throws ForgeReadFailed
     */
    public function checked(?string $etag): ?string
    {
        if (null !== $etag && 1 !== preg_match(self::ENTITY_TAG, $etag)) {
            throw new ForgeReadFailed('GitHub answered a validator that is not an entity tag.');
        }

        return $etag;
    }
}
