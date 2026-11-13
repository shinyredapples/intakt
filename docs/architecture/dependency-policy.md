# Dependency policy

Whether a head's change to its dependencies is routine or needs the owner (plan task T52, design: [Merge, mechanical checks](../design/05-architecture/08-merge.md#mechanical-checks)). The policy belongs to the `language` plugin kind; the PHP plugin decides Composer's `composer.json` and `composer.lock`. `php bin/console intakt:dependencies:decide <project> <head>` runs it on the project's mirror. Nothing parks or merges on the answer yet: that is T62 and T64. The checks against OSV.dev and deps.dev (advisories, release age) are T77.

## Modules

- `src/Plugin/Language/Contract/`: the `Language` interface (`dependencyFiles()`, the files the policy reads from the repository's root; `decideDependencies(DependencyFiles $old, DependencyFiles $new)`), `DependencyFiles` (each file's contents by path, a file the revision lacks left out), `DependencyDecision` (the findings, each a `DependencyFinding` with a subject and a reason, and the new packages, sorted and listed once; `needsOwner()` is true when there is a finding) and `DependencyFinding`.
- `src/Plugin/PhpLanguage/`: `Infrastructure/PhpLanguage`, the only implementation, so the container autowires it; `Domain/ComposerPolicy` and its rules; `Infrastructure/GitUpstreamTags`, which reads a tag with `git ls-remote`.
- `src/Core/Git/UseCase/DecideDependencyChange/`: `DecideDependencyChange` reads the plugin's files at the head and at each merge base of the head with main, through `HardenedGit` (`ls-tree -z` for the entry, `cat-file blob` for the contents), and asks the plugin once per merge base; the decisions are combined, so a criss-cross history with several bases hides nothing. Main, the merge bases (the empty tree when there is no common ancestor), the full-commit-id rule and the refusal of a shallow mirror are the ones of [protected paths](protected-paths.md) (`ChangedPaths`).

## The old side is the merge base

The old files are read at the merge base of the head with main, never at main's tip: a branch behind main that adds one package is asked about that package alone, and the packages main added since are not listed as removed (`DecideDependencyChangeTest`).

## Rules

`ComposerState` reads both files at one revision; a missing file reads as empty. Package names are compared in lower case.

- **Settings.** Every key of `composer.json` besides `require` and `require-dev` is one of Composer's own settings (`scripts`, `config` with `allow-plugins`, `repositories`, `autoload`, `minimum-stability`, `extra`, ...), and so is every key of `composer.lock` besides `_readme`, `content-hash`, `packages`, `packages-dev`, `stability-flags`, `platform`, `platform-dev` and `plugin-api-version` (which Composer rewrites or derives from the requirements). Any change to one needs the owner, named as `composer.json <key>` or `composer.lock <key>`.
- **Requirements** (`RequirementChanges`). A removed requirement needs the owner. A new one needs the owner unless its vendor is trusted (a platform requirement such as `ext-intl` has no vendor). A move between `require` and `require-dev` is routine only for a trusted package that keeps its constraint. A constraint change is routine. A stability flag in a constraint (`@beta`, `@RC`, `@dev`, ...) is routine when the package had it already, or when the change adds the package and the flag is `@beta`, `@RC` or `@stable`; the lock file's `stability-flags` entry follows the same rule (5 and 10, RC and beta).
- **Locked packages** (`LockedPackageChanges`), transitive ones included. A removed package needs the owner, and so does a move between `packages` and `packages-dev` of a package whose vendor is not trusted. A new package is routine only when all of these hold:
  - its vendor is in `trusted_vendors` (otherwise nothing else is checked and nothing is looked up);
  - its type is in `package_types` and is not `composer-plugin` (a Composer plugin always needs the owner);
  - it has no `bin` and no `autoload.files`;
  - its source (a git `https://github.com/<owner>/<repository>.git` URL) and its dist (a zip of `https://api.github.com/repos/<owner>/<repository>/zipball/<commit>`) agree on one repository and one commit, at least one of them is present, and the owner is the package's vendor (so `doctrine/doctrine-bundle` from `doctrine/DoctrineBundle` passes);
  - its version is a release tag (no `dev-` branch, no `-dev`), and the upstream repository's tag names exactly that commit.
- An updated package (another version, commit, type, binaries or autoload files) is routine when its type, binaries and autoload files stay the same, its source and dist still name one commit in the same repository, and that commit is the upstream tag of its version. This holds for every vendor, so Dependabot's updates stay routine.

The tag check is needed because GitHub serves a commit pushed to any fork under the upstream repository's address too. `GitUpstreamTags` runs `git ls-remote <clone URL> refs/tags/<version> refs/tags/<version>^{}` through `NetworkGit` (retried after 10 and 30 seconds), on the `https://github.com/<owner>/<repository>.git` URL Intakt builds from the checked owner and repository, never on a URL copied from the lock file, with `--git-dir=/nonexistent`, so no repository config applies, and takes the peeled commit of an annotated tag; only exact ref names count. A tag that does not exist, a commit that differs and a remote that cannot be read all need the owner.

A file that cannot be read needs the owner, with the reason: invalid JSON, no JSON object, `packages` that is no list, a package without a name or version, a name locked or required twice (in any case, or in both groups), a constraint that is no string, `stability-flags` that is no map. So does a manifest that is no regular file at a revision (a symbolic link, a folder or a submodule) and a change that cannot be read at all.

## Owner config

The section `composer_policy` of the owner's config file (`config/intakt.yaml`, or the file `INTAKT_CONFIG` names) holds the rules for every project. `config/packages/ai.php` processes it with `Infrastructure/ComposerPolicyConfiguration` into the parameters `intakt.php_language.trusted_vendors` and `intakt.php_language.package_types` (`PhpLanguageServices`). `trusted_vendors` defaults to `symfony` and `doctrine`; a vendor is written without its slash and must follow Composer's rule for a vendor name, or Intakt does not start. `package_types` defaults to `library`, `symfony-bundle` and the types of the official Symfony AI components (`symfony-ai-platform`, `symfony-ai-store`, `symfony-ai-message-store`, `symfony-ai-tool`, `symfony-ai-mate`). A list the owner sets replaces its default.

## The command

`intakt:dependencies:decide <project> <head>` prints `new package <name>` for each new package, `owner <subject>: <reason>` for each finding, then `decision: routine` or `decision: needs the owner`, and exits with 0. Names and reasons come from the change's own files, so control characters are escaped. A head that is not a full commit id and a project name that cannot be a folder name are refused with exit 1.
