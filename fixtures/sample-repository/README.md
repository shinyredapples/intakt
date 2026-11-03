# Sample repository

A small shop project with a base and a pull request branch that carries three deliberate issues, for trying a review. It is plain files, not a git repository: `base/` is the base commit and `pull-request/` holds the files the branch adds or changes on top of it.

`issues.json` lists the issues (`id`, `kind`, `file`, `description`, and an `evidence` line that appears as an added line in the change), so a later check can tell whether a review found them.

Build the repository (from the repository root, with `$TARGET` an empty directory):

```
git -C "$TARGET" init --initial-branch=main
cp -R fixtures/sample-repository/base/. "$TARGET"
git -C "$TARGET" add --all && git -C "$TARGET" commit -m "Add the shop helpers"
git -C "$TARGET" checkout -b sample-change
cp -R fixtures/sample-repository/pull-request/. "$TARGET"
git -C "$TARGET" add --all && git -C "$TARGET" commit -m "Add shipping, a report and change the discount"
```
