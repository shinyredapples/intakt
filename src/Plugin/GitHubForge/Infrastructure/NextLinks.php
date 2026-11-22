<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// RFC 8288 (https://www.rfc-editor.org/rfc/rfc8288#section-3.3), because a looser match would take `rel="next-page"` as a next page, or miss `rel = "next"` and end a listing early.
final readonly class NextLinks
{
    // RFC 9110 token and quoted-string, because a `Link` parameter's name and value take these two forms.
    private const string TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
    private const string QUOTED = '"(?:[^"\\\\]|\\\\.)*"';

    /**
     * @param list<string> $links
     *
     * @throws ForgeReadFailed
     */
    public function namesNext(array $links): bool
    {
        return array_any($links, fn (string $link): bool => in_array('next', $this->relationsOf($link), true));
    }

    // A `Link` this forge cannot read fails the listing, because taking it as naming no next page would end the listing early.
    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    private function relationsOf(string $link): array
    {
        $value = '<[^>]*>((?:\s*;\s*'.self::TOKEN.'\s*(?:=\s*(?:'.self::QUOTED.'|'.self::TOKEN.'))?)*)';
        if (1 !== preg_match('/\A\s*'.$value.'(?:\s*,\s*'.$value.')*\s*\z/', $link) || false === preg_match_all('/'.$value.'/', $link, $values)) {
            throw $this->unreadable();
        }

        return array_merge(...array_map($this->relationsIn(...), $values[1]));
    }

    // Only the first `rel` of a link counts, relation types compare without case and a quoted-pair stands for its character, because RFC 8288 and RFC 9110 say so.
    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    private function relationsIn(string $parameters): array
    {
        if (false === preg_match_all('/\G\s*;\s*('.self::TOKEN.')\s*(?:=\s*(?:('.self::QUOTED.')|('.self::TOKEN.')))?/', $parameters, $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            throw $this->unreadable();
        }

        // Any `anchor` counts as another resource, because its relations then describe that resource and not the page GitHub served (RFC 8288 section 3.2).
        if (array_any($found, static fn (array $parameter): bool => 'anchor' === strtolower($parameter[1]))) {
            return [];
        }

        $rel = array_find($found, static fn (array $parameter): bool => 'rel' === strtolower($parameter[1]));
        $relations = null === $rel ? '' : ($rel[3] ?? (string) preg_replace('/\\\\(.)/s', '$1', substr((string) $rel[2], 1, -1)));
        $types = preg_split('/\s+/', strtolower($relations));

        return array_values(array_filter(false === $types ? [] : $types, static fn (string $relation): bool => '' !== $relation));
    }

    private function unreadable(): ForgeReadFailed
    {
        return new ForgeReadFailed('GitHub answered a Link header this forge cannot read.');
    }
}
