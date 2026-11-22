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
     * @param string       $target the requested path and query
     *
     * @throws ForgeReadFailed
     */
    public function namesNext(array $links, string $target): bool
    {
        // Every link is read before the answer, because an unreadable link after a plain `next` must still fail the listing.
        $relations = array_merge([], ...array_map(fn (string $link): array => $this->relationsOf($link, $target), $links));

        return in_array('next', $relations, true);
    }

    // A `Link` this forge cannot read fails the listing, because taking it as naming no next page would end the listing early.
    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    private function relationsOf(string $link, string $target): array
    {
        $value = '<[^>]*>((?:\s*;\s*'.self::TOKEN.'\s*(?:=\s*(?:'.self::QUOTED.'|'.self::TOKEN.'))?)*)';
        if (1 !== preg_match('/\A\s*'.$value.'(?:\s*,\s*'.$value.')*\s*\z/', $link) || false === preg_match_all('/'.$value.'/', $link, $values)) {
            throw $this->unreadable();
        }

        return array_merge([], ...array_map(fn (string $parameters): array => $this->relationsIn($parameters, $target), $values[1]));
    }

    // Only the first `rel` of a link counts, relation types compare without case and a quoted-pair stands for its character, because RFC 8288 and RFC 9110 say so.
    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    private function relationsIn(string $parameters, string $target): array
    {
        if (false === preg_match_all('/\G\s*;\s*('.self::TOKEN.')\s*(?:=\s*(?:('.self::QUOTED.')|('.self::TOKEN.')))?/', $parameters, $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            throw $this->unreadable();
        }

        $rel = array_find($found, static fn (array $parameter): bool => 'rel' === strtolower($parameter[1]));
        $types = preg_split('/\s+/', strtolower(null === $rel ? '' : $this->valueOf($rel)));
        $relations = array_values(array_filter(false === $types ? [] : $types, static fn (string $relation): bool => '' !== $relation));
        $anchors = array_filter($found, static fn (array $parameter): bool => 'anchor' === strtolower($parameter[1]));
        // A value-less `anchor` is no reference at all (RFC 8288 requires one), so the header is unreadable.
        if (array_any($anchors, static fn (array $anchor): bool => null === $anchor[2] && null === $anchor[3])) {
            throw $this->unreadable();
        }

        // An anchored `next` that is not provably this page fails, because RFC 8288 section 3.2 gives it to another resource, and dropping it could end the listing early.
        if (in_array('next', $relations, true) && array_any($anchors, fn (array $anchor): bool => !$this->namesThisRequest($this->valueOf($anchor), $target))) {
            throw $this->unreadable();
        }

        return $relations;
    }

    /**
     * @param array<int, ?string> $parameter
     */
    private function valueOf(array $parameter): string
    {
        return $parameter[3] ?? (string) preg_replace('/\\\\(.)/s', '$1', substr((string) $parameter[2], 1, -1));
    }

    // Only the empty reference, or a relative one of the same path (or none) and the same raw query pairs, counts, because anything looser may name another resource.
    private function namesThisRequest(string $reference, string $target): bool
    {
        if ('' === $reference) {
            return true;
        }

        $request = parse_url($target);
        $given = parse_url($reference);
        if (false === $request || false === $given || str_contains($reference, '#') || [] !== array_diff(array_keys($given), ['path', 'query'])) {
            return false;
        }

        return ($given['path'] ?? ($request['path'] ?? '')) === ($request['path'] ?? '') && $this->pairsOf($given['query'] ?? null) === $this->pairsOf($request['query'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function pairsOf(?string $query): array
    {
        // Raw pairs and not `parse_str`, because it keeps only the last of a repeated parameter and `state=closed&state=open` would read as `state=open`.
        $pairs = null === $query ? [] : explode('&', $query);
        sort($pairs);

        return $pairs;
    }

    private function unreadable(): ForgeReadFailed
    {
        return new ForgeReadFailed('GitHub answered a Link header this forge cannot read.');
    }
}
