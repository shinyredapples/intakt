---
name: intakt-docs
description: Writes or changes Intakt documentation so people can read it. Use when a change touches docs/, README.md or a docs/user chapter. Gives the writing rules, two good and two bad examples from this repository and the checklist the docs gate and reviewers apply.
---

# Writing Intakt documentation

Documentation is for a person who is not in a hurry to learn it. A page that only grows is a page nobody reads, so a change rewrites a short section and never appends to a paragraph.

## Rules

1. A user chapter in `docs/user/` is a short scene, then numbered steps. One idea per section.
2. A paragraph has at most 80 words (the gate's limit; aim for 60). A list item counts as a paragraph.
3. A user or guide page has at most 1,500 words. When it grows, split it into chapters.
4. A technical page in `docs/architecture/` over 1,500 words opens, right after its title, with an "In short" section of at most 120 words. Every change keeps it true.
5. Detail only contributors need goes to `docs/reference/` or `docs/architecture/`, never into `docs/user/`.
6. A change adds or rewrites a short section and updates the page's summary. It does not extend a paragraph.
7. State only what the code does. Verify each name against the code. US English, no long dashes.

## The docs gate

`bin/check --only=docs` fails a paragraph, page or summary over its limit and says how to fix it. `ratchet/docs.json` lists what was over a limit before the gate existed. It only shrinks: an edited listed paragraph gets a new key and must meet the limit, and an entry added or a ceiling raised fails. Never fix a finding by adding to the list.

When you shorten a listed paragraph or page, or add the missing summary, remove its entry or lower its ceiling in the same change; the gate names each entry that is no longer needed. Never delete the file itself: keep it with empty lists.

## Good examples

A scene, then what to do (`docs/user/08-when-something-goes-wrong.md`, "A review that will not finish"): a Friday afternoon and a page that does not move, then the first place to look.

A section that is a short list of one idea each (`docs/user/04-reading-the-verdict.md`, "The judge's call"): one sentence introduces the three outcomes, then one item per outcome, each a single sentence.

## Bad examples

`docs/architecture/dashboard.md` is one "In short" section of tens of thousands of words: a summary that is the whole page, because each change appended its rules to it.

`docs/architecture/quality-gates.md` has a single paragraph of more than 3,000 words (line 18): a reader cannot find anything in it. Split a run of rules into one list item per rule.

Both pages are on the ratchet list and are rewritten one page at a time.

## Checklist

- [ ] The page opens with what the reader can do or what the thing is.
- [ ] Every paragraph is at most 80 words; every list item is one idea.
- [ ] The page is within its limit (1,500 words for user and guide pages).
- [ ] A long technical page has an "In short" of at most 120 words, and it is still true.
- [ ] Contributor detail is in `docs/reference/` or `docs/architecture/`.
- [ ] Every command, option and name is checked against the code with `git grep`.
- [ ] `bin/check --only=docs` passes without touching `ratchet/docs.json` except to remove entries or lower ceilings.
