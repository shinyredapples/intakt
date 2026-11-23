<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The kinds of part the reader of a Markdown text tells apart, because each is shown differently: a fence as code, a code span and a literal run as text, a comment not at all.
enum MarkdownPart: string
{
    case Fence = 'fence';
    case FenceAfterAMarker = 'fence after a marker';
    case Code = 'code';
    case Comment = 'comment';
    case Literal = 'literal';
}
