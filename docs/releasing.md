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

Release tags are protected by the repository ruleset "Release tags: owner only" (set by the owner on 2026-11-11). Only the owner, as the repository and organization admin, can create, move or delete a `v*` tag. So no other account, the build loop's app included, can start the release workflow with its `packages: write` token by pushing a tag.

## The container image

Every pushed release tag publishes the app image to GitHub's container registry: the `image` job of `.github/workflows/release-image.yml` checks the tag out apart (in `release/`), builds the image from it, runs `docker/smoke-test.sh` on it, and pushes `ghcr.io/shinyredapples/intakt:<version>` (the tag without its `v`, for example `0.1.0`). A failed smoke test pushes nothing. The smoke test is the workflow's own, so an older tag is checked against today's test; if that test asks for something the old image lacks, the run fails and nothing is pushed.

Then the `latest` job points `:latest` at the highest release tag in version order, as the tags are at that moment, and only once that tag's image is on the registry. A registry it cannot read (an outage, a refused token) fails the job, so it shows red and can be run again, rather than leaving `latest` behind. Its runs go one at a time across all tags, and none of them uses its own tag, so two releases close together can finish in any order and `latest` never moves back. For a tag cut before the workflow existed, or after a failed run, publish it by hand: `gh workflow run release-image.yml -f tag=v0.1.0`. The script runs from the workflow's own revision, so this works for a tag whose tree has no release script.

A new package on the registry is private, whatever the repository's visibility. After the first publish, the owner makes it public once in the package's settings (Package settings, Danger Zone, Change visibility); while it is private, pulling needs a GitHub token with `read:packages` (`docker login ghcr.io`).

## Release notes

`v0.1.0` has hand-written notes. Every later release uses GitHub's generated notes since the previous tag.

GitHub generates the notes from the merged pull requests since the previous tag, and groups them only by pull-request label. So `.github/workflows/release-labels.yml` labels every pull request with the Conventional Commit type of its title (the part before the optional scope, `!` and colon) when it is opened, reopened or its title changes (an edit that leaves the title alone and a new push change nothing, because the label follows the title): `feat`, `fix` or `docs`, and it removes a label of the three that no longer matches. It creates a missing label first. It never checks out the pull request, and it skips a pull request from a fork, whose token cannot write labels.

`.github/release.yml` then groups the notes by those labels: `feat` (Features), `fix` (Fixes), `docs` (Docs), and everything else under "Other changes". Pull requests by `dependabot` are left out. A pull request merged before the workflow existed, or from a fork, has no label and lands under "Other changes"; label it by its title type before generating the notes. A pull request retitled before the workflow reacted to title edits on `main`, or whose labeling run failed, can carry the labels of its old title: a stale `feat`, `fix` or `docs` label, or no label for a title that gained one of those types (a `chore:` retitled `fix:`), which lands under "Other changes". Before generating the notes, correct such a stale label or add the missing one by hand.

## The Next draft

A draft release named "Next" is refreshed at every train with what was merged to `main` since the last tag, so the next release's notes can be read before it is cut. Drafts are visible only to the repository's collaborators.
