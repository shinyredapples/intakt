<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use NoDiscard;

interface Forge
{
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     */
    public function change(ChangeId $id): Change;

    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     */
    #[NoDiscard]
    public function hasHeadMoved(Change $change): bool;

    /**
     * @throws ForgeReadFailed
     */
    public function headMoveSince(CommitId $earlier, Change $change): HeadMove;

    /**
     * @throws FileNotFoundAtHead
     * @throws ForgeReadFailed
     */
    public function fileAtHead(Change $change, string $path): FileContent;
}
