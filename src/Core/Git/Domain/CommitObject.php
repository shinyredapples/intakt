<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

// A commit as git stores it, header lines and a message, because signing and checking an SSH signature work on exactly this text.
final readonly class CommitObject
{
    private const array SIGNATURE_HEADERS = ['gpgsig', 'gpgsig-sha256'];

    /**
     * @param list<string> $headers
     */
    private function __construct(
        private array $headers,
        private string $message,
    ) {
    }

    public static function of(string $raw): self
    {
        [$headers, $message] = explode("\n\n", $raw, 2) + [1 => ''];

        return new self(explode("\n", $headers), $message);
    }

    // Moves each parent that was replaced to its replacement, so a rewritten commit sits on rewritten parents.
    /**
     * @param array<string, string> $replaced
     */
    public function reparented(array $replaced): self
    {
        return new self(
            array_map(static fn (string $line): string => str_starts_with($line, 'parent ') ? 'parent '.($replaced[substr($line, 7)] ?? substr($line, 7)) : $line, $this->headers),
            $this->message,
        );
    }

    public function signedWith(string $signature, string $header): self
    {
        $unsigned = $this->withoutSignature();

        $lines = explode("\n", $header.' '.str_replace("\n", "\n ", rtrim($signature)));

        return new self([...$unsigned->headers, ...$lines], $this->message);
    }

    public function withoutSignature(): self
    {
        $kept = [];
        $inSignature = false;

        foreach ($this->headers as $line) {
            // Because a header's value continues on the lines that start with a space.
            if (!str_starts_with($line, ' ')) {
                $inSignature = in_array(explode(' ', $line, 2)[0], self::SIGNATURE_HEADERS, true);
            }

            if (!$inSignature) {
                $kept[] = $line;
            }
        }

        return new self($kept, $this->message);
    }

    // The commit without its signature header, because that is the text a signature covers.
    public function payload(): string
    {
        return $this->withoutSignature()->text();
    }

    public function signature(string $header): ?string
    {
        $lines = [];
        $inSignature = false;

        foreach ($this->headers as $line) {
            if (str_starts_with($line, $header.' ')) {
                $inSignature = true;
                $lines[] = substr($line, strlen($header) + 1);
            } elseif ($inSignature && str_starts_with($line, ' ')) {
                $lines[] = substr($line, 1);
            } else {
                $inSignature = false;
            }
        }

        return [] === $lines ? null : implode("\n", $lines)."\n";
    }

    public function text(): string
    {
        return implode("\n", $this->headers)."\n\n".$this->message;
    }
}
