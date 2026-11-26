<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// The reading keeps where the open text sits, because text directly below a list item line is that item's wrapped text and any ordered item ends it, while a paragraph of its own ends only at a non-empty item numbered 1.
enum TextPlace
{
    case BelowItem;
    case Apart;
}
