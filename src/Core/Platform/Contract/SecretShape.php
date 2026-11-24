<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// A match consumes its text, so a token that starts inside it and ends further on would be missed. A shape whose match can hold such a start marks with a `resume` group where the scan goes on after a match; every other shape goes on after its end. Each shape tries a start only where a later start could not reach further, so each character is read a bounded number of times and the time grows linearly.
final readonly class SecretShape
{
    private string $pattern;

    public function __construct(string $shape)
    {
        $this->pattern = '~(?:'.$shape.')(?<end>)~s';
    }

    public function addFoundIn(string $text, RedactedSpans $spans): bool
    {
        $offset = 0;
        while (true) {
            $found = preg_match($this->pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset);
            if (1 !== $found) {
                return 0 === $found;
            }
            $spans->add($match[0][1], $match['end'][1]);
            $offset = isset($match['resume']) ? $match['resume'][1] : $match['end'][1];
        }
    }
}
