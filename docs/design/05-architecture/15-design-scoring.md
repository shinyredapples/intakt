# Design scoring

Status: decided design (owner decision 2026-11-04, issue #863); nothing of it is built yet. The plan tasks are T84 (objective checks), T85 (the rubric) and T86 (the taste ladder) in [the plan](../../plan.md#the-designer).

The Designer (see [the team](01-overview.md#the-team)) makes user-facing work measurable in three layers, from hard to soft: objective checks that block, a scored rubric with evidence, and taste learned from the owner by pairwise comparison. When the Designer is active and what it adds before a build are in [Reviews](07-reviews.md#the-designer); this page holds the scoring.

## Layer 1: objective checks that block

Each item is a check of its own, run by the check job like any other check of the project, so plain code decides, never a model: a rule that can be measured belongs in a check, not in a reviewer's reading (as in [Findings that recur become checks](07-reviews.md)). A failing check sends the head back to the Developer like any red check, with the check's output as its finding.

- **Accessibility:** no axe-core violation of serious or critical impact on any page the change touches, and every text passes the contrast check.
- **Layout at each width:** at each of the three widths (phone, tablet, desktop), no horizontal scroll, no overlapping elements, no text that overflows its box, and every touch target at least 44 px in both directions.
- **Design tokens only:** every color, spacing and type value in the change comes from the project's design tokens; a literal value that is not a token fails.
- **Lighthouse thresholds:** each page meets the scores the project sets for it.
- **Screenshot comparison with main:** each screen is captured at each width on the head and on main, and compared region by region. The Designer's design for the task ([Reviews](07-reviews.md#the-designer)) names each screen the task changes and, on it, the regions expected to change (each an element of the page, named by a selector, so it holds at every width). A difference inside an expected region passes; a difference anywhere else fails as an unintended change, on a named screen as on any other, with both screenshots and the difference attached. So a footer that changes on a page named only for a copy edit in its header fails. A named screen with no expected region counts as expecting no change.
  - *Changed, added, removed:* each expected region says which of the three it is, and plain code checks the selector on the side where the element must exist. A changed region must match on main and on the head. An added one must match on the head and nothing on main; a removed one must match on main and nothing on the head, so an intended deletion passes. A selector that does not match as its kind says fails, so a stale design cannot hide a difference. The region's area is the element's box on each side where it exists. What an addition or a removal moves around it passes only inside a region the design names too (for example the list that gains or loses a row, named as changed); anything else that moves fails.
  - *The exemptions are bounded by plain code.* The Designer reads untrusted text, so the regions it names are not trusted to be narrow. A selector that matches the page's root or body element exempts nothing, and neither do the regions of a screen that together cover more than a share of it the project sets (a protected setting), at any width. Such a screen is compared as if it had no expected region, and its differences go to the owner, who accepts them for that head or sends it back; a redesign of a whole screen is the owner's to accept. Every expected region and the differences that passed inside it are listed on the pull request, so the reviewers and the owner see what the comparison let through, and the other objective checks run on those regions like anywhere else.

## Layer 2: the rubric

On a user-facing head the Designer scores each screen the change touches against fixed usability heuristics: clarity, feedback, states, consistency, error recovery and copy. The heuristics are fixed so that scores stay comparable over time; the rubric task (T85) picks the scale.

- **Every score cites evidence:** a region of a named screenshot (the screen, the width and the area) and what is wrong there. The scores are part of the Designer's typed verdict, and plain code refuses a score without its evidence, as it refuses any invalid verdict ([Reviews](07-reviews.md)).
- **A low score is a review finding, not a gate.** It enters the Designer's verdict as a finding with its evidence and goes through the judge like any reviewer's finding; no score threshold blocks a merge on its own.
- **Scores are kept per screen over time.** Each score is stored with the screen, the width, the heuristic and the head, so a screen's history can be read back, and a score below the screen's last score on main is a regression, shown on the [dashboard](13-dashboard.md#design-scores).

## Layer 3: taste by blind pairwise comparison

Taste cannot be checked, so it is learned from the owner and measured.

- **Variants and a ladder:** for a visible design choice (a layout, an emphasis, a tone of copy), the Designer creates variants and compares them in pairs. Each comparison is blind: the variants are shown under neutral names in random order, without saying which one is the current design or which one came first. The pairwise results rank the variants into a preference ladder.
- **What the ranking session sees:** names and order alone are not blind if the judge remembers making the variants or has seen main. So the session that writes the variants never ranks them. Each pair is ranked by a fresh session with no tools and no memory of the task's sessions; plain code builds its whole input from an allow-list: the two screenshots the check job rendered, under neutral names, the task's stated outcome and the fixed heuristics. It gets no variant source, no diff, no screenshot of main, no plan or design text and no transcript of the session that wrote the variants. The neutral names are drawn at random per pair, so they carry no order. The mapping from a neutral name to its variant stays with the orchestrator.
- **The owner votes on a sample:** the owner gets a sample of the pairs, not every pair, shown the same blind way and without the Designer's choice. The Designer's agreement with the owner (the share of sampled pairs where both chose the same variant) is measured and shown on the [dashboard](13-dashboard.md#design-scores).
- **Asking follows agreement:** while agreement is low, a choice with character goes to the owner as options (a question that parks the task, see [Questions and steering](05-questions-and-steering.md)). As measured agreement rises, fewer such choices are asked, and the Designer takes the top of its ladder. Agreement only ever comes from the owner's own votes; the ladder task (T86) sets how the share of questions follows it.

## Tools

The tools these layers need (axe-core, Lighthouse and a headless browser for the screenshots) are opt-in per project and run in the check environment, the same image the check job and CI use ([Reviews](07-reviews.md)). The simplest install needs none of them: they are not part of the base image, and a project without a user interface never installs them. A layer whose tool a project has not enabled does not run, and the dashboard says so; nothing passes in its name. A project that enables a tool declares it in its requirements page like any other tool its checks need.

## Trust

The Designer is a model role that reads untrusted text (the task, the diff and the screenshots of code under review), so it is listed in the [threat model](14-threat-model.md#designer). Its rubric scores and its ladder never approve or merge anything, and Layer 1 is plain code.
