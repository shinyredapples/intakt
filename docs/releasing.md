# Releasing

How Intakt is released. The release notes are the changelog: no CHANGELOG file is kept by hand.

## How versions are chosen

Intakt uses semantic versions. Until the public API settles the major version stays `0`, so a minor bump may break things and a patch bump only fixes.

Planned versions (none is cut yet):

| Version | When |
| --- | --- |
| `v0.1.0` | M2 works: the first usable `intakt:review` |
| `v0.2.0` to `v0.4.0` | M3 and M4 |
| the hackathon submission | M5, together with the container image |

`v1.0.0` comes when the public API settles. Every release before it is marked pre-release on GitHub.

## What goes into a release

Everything merged to `main` since the previous tag. Every commit follows Conventional Commits, and `main` is only changed through pull requests that passed the gates, so the history is the content of the release. Nothing else is added by hand.

## Cutting a release

Releases are cut by the owner. A build or review job never sets a tag and never publishes a release.

1. Check that `main` is green: the latest `main` run of the checks is successful, and `bin/check` passes on a fresh checkout of `main`.
2. Tag the commit with an annotated, signed tag: `git tag -s v0.1.0 -m "v0.1.0"`.
3. Push the tag: `git push origin v0.1.0`.
4. On the [releases page](https://github.com/shinyredapples/intakt/releases) create a release from that tag, choose "Generate release notes", tick "Set as a pre-release" (for every version before `v1.0.0`), and publish.

## Release notes

GitHub generates the notes from the merged pull requests since the previous tag, and groups them only by pull-request label. So `.github/workflows/release-labels.yml` labels every pull request with the Conventional Commit type of its title (the part before the optional scope, `!` and colon) when it is opened, reopened or its title is edited: `feat`, `fix` or `docs`, and it removes a label of the three that no longer matches. It creates a missing label first. It never checks out the pull request, and it skips a pull request from a fork, whose token cannot write labels.

`.github/release.yml` then groups the notes by those labels: `feat` (Features), `fix` (Fixes), `docs` (Docs), and everything else under "Other changes". Pull requests by `dependabot` are left out. A pull request merged before the workflow existed, or from a fork, has no label and lands under "Other changes"; label it by its title type before generating the notes.
