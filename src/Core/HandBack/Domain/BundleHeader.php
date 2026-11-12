<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The header of a git bundle as gitformat-bundle(5) defines it, read by Intakt because git prints a bundle's prerequisites only as prose.
final readonly class BundleHeader
{
    private const array SIGNATURES = ['v2' => '# v2 git bundle', 'v3' => '# v3 git bundle'];

    private const array ID_LENGTHS = ['sha1' => 40, 'sha256' => 64];

    /**
     * @param list<string>          $prerequisites
     * @param array<string, string> $references    the id each ref name points at
     */
    private function __construct(
        public array $prerequisites,
        public array $references,
    ) {
    }

    // The header's lines without the empty line that ends it; anything else than what git writes is no header, including a filtered bundle, which would leave objects out.
    public static function parse(string $header): ?self
    {
        $lines = explode("\n", $header);
        $version = array_search(array_shift($lines), self::SIGNATURES, true);

        if (false === $version) {
            return null;
        }

        $format = 'sha1';
        while ('v3' === $version && str_starts_with($lines[0] ?? '', '@')) {
            $format = self::objectFormat((string) array_shift($lines));

            if (null === $format) {
                return null;
            }
        }

        return self::entries($lines, self::ID_LENGTHS[$format]);
    }

    /**
     * @return 'sha1'|'sha256'|null
     */
    private static function objectFormat(string $capability): ?string
    {
        return match ($capability) {
            '@object-format=sha1' => 'sha1',
            '@object-format=sha256' => 'sha256',
            default => null,
        };
    }

    /**
     * @param list<string> $lines
     */
    private static function entries(array $lines, int $idLength): ?self
    {
        $id = '[0-9a-f]{'.$idLength.'}';
        $prerequisites = [];
        $references = [];

        foreach ($lines as $line) {
            if (1 === preg_match('/^-('.$id.')(?: .*)?$/D', $line, $prerequisite)) {
                $prerequisites[] = $prerequisite[1];

                continue;
            }

            if (1 !== preg_match('/^('.$id.') ([^ ]+)$/D', $line, $reference) || array_key_exists($reference[2], $references)) {
                return null;
            }

            $references[$reference[2]] = $reference[1];
        }

        return new self($prerequisites, $references);
    }
}
