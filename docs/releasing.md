# Releasing

How Intakt is released. The release notes are the changelog: no CHANGELOG file is kept by hand.

## The release train

Releases follow a train. Twice a day, at 13:00 and 21:00 Europe/Berlin, a timer on the owner's host takes the newest `main` commit whose `ci.yml` run for the push succeeded. It cuts a release from that commit only when at least one `feat` or `fix` commit landed since the previous tag; otherwise that train is skipped and no release is made.

The timer is the owner's tooling and lives outside this repository. A train cuts the release the way a release by hand is cut (see [Cutting a release by hand](#cutting-a-release-by-hand)): a signed tag and a pre-release with generated notes. It also refreshes the [Next draft](#the-next-draft), which the hand-cut steps do not.

## How versions are chosen

Intakt uses semantic versions. Until the public API settles the major version stays `0`. `v1.0.0` comes when the public API settles; every release before it is marked pre-release on GitHub.

Before `v1.0.0` the two lower numbers mean:

- **The patch counts trains.** Each train that cuts a release raises the patch by one. A train may carry features, but never a breaking change.
- **The minor moves at a milestone or at a breaking change.** The owner chooses the milestone versions. A breaking change is a Conventional Commit with `!` after the type or scope (`feat!:`, `fix(config)!:`), or with a `BREAKING CHANGE` footer; the release that carries it raises the minor.

Planned versions:

| Version | When |
| --- | --- |
| `v0.1.0` | cut on 2026-11-11: the review loop. M3 and M4 were done; M2's provider-limit tasks #19, #415 and #416 were still open |
| `v0.1.1`, `v0.1.2` and so on | the trains after `v0.1.0` |
| `v0.2.0` | the hackathon submission (M5) |

## What goes into a release

Everything merged to `main` since the previous tag. Every commit follows Conventional Commits, and `main` is only changed through pull requests that passed the gates, so the history is the content of the release. Nothing else is added by hand.

## Who tags

Every tag is annotated and signed with the owner's key, set either by the timer on the owner's host or by the owner by hand. A build or review job never sets a tag and never publishes a release.

## Cutting a release by hand

The owner cuts a release by hand for a milestone version, or when the timer is off. The version follows [How versions are chosen](#how-versions-are-chosen).

1. Check that `main` is green: the latest `main` run of the checks is successful, and `bin/check` passes on a fresh checkout of `main`.
2. Tag the commit with an annotated, signed tag: `git tag -s v0.2.0 -m "v0.2.0"`.
3. Push the tag: `git push origin v0.2.0`.
4. On the [releases page](https://github.com/shinyredapples/intakt/releases) create a release from that tag, choose "Generate release notes", tick "Set as a pre-release" (for every version before `v1.0.0`), and publish.

## Release notes

`v0.1.0` has hand-written notes. Every later release uses GitHub's generated notes since the previous tag.

GitHub generates the notes from the merged pull requests since the previous tag, and groups them only by pull-request label. So `.github/workflows/release-labels.yml` labels every pull request with the Conventional Commit type of its title (the part before the optional scope, `!` and colon) when it is opened, reopened or its title is edited: `feat`, `fix` or `docs`, and it removes a label of the three that no longer matches. It creates a missing label first. It never checks out the pull request, and it skips a pull request from a fork, whose token cannot write labels.

`.github/release.yml` then groups the notes by those labels: `feat` (Features), `fix` (Fixes), `docs` (Docs), and everything else under "Other changes". Pull requests by `dependabot` are left out. A pull request merged before the workflow existed, or from a fork, has no label and lands under "Other changes"; label it by its title type before generating the notes.

## The Next draft

A draft release named "Next" is refreshed at every train with what was merged to `main` since the last tag, so the next release's notes can be read before it is cut. Drafts are visible only to the repository's collaborators.
