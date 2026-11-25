<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;


final readonly class BranchHead
{
    private const string OBJECT_ID = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D';

    /**
     * @throws ObjectIdMalformed
     */
    public function __construct(
        public string $branch,
        public string $commit,
    ) {
        // Because a refspec takes a ref name or an option as readily as an object id.
        if (1 !== preg_match(self::OBJECT_ID, $commit)) {
            throw new ObjectIdMalformed(sprintf('"%s" is no object id', $commit));
        }
    }
}
