# Designer scoring layers (planned)

How the Designer ([Reviews](07-reviews.md#the-designer-planned), [Overview](01-overview.md#the-team)) judges user-facing work, in three layers: objective checks that block, a heuristic rubric with evidence, and taste learned from the owner by pairwise comparison. Each layer does what the one before it cannot: a check settles what a tool can measure, the rubric covers usability a tool cannot measure, and the taste ladder covers choices where no rule says which is better. Like the role itself, the scoring runs only for a user-facing task of a project with a user interface. Nothing on this page is built yet.

## Layer 1: objective checks that block

Each item is a check, not a reviewer's judgement, because a rule a tool can check is a gate rule ([Principles](../02-principles.md), "Coding rules live in gates"). A failing check blocks the merge like every other check; it does not hold the other reviewers. Only the Designer's own scoring waits for them: it scores a head once its Layer 1 checks have run, since the rubric reads their screenshots and results.

- **Accessibility:** no serious or critical axe-core violations, and passing color contrast.
- **Layout at each width** (phone, tablet, desktop): no horizontal scroll, no overlapping elements, no text overflow, and touch targets of at least 44 px.
- **Design tokens:** colors, spacing and type come only from the project's design tokens.
- **Lighthouse:** each page meets the project's Lighthouse thresholds for that page.
- **Screenshots against main:** each screen at each width is rendered at the head and compared with its reference screenshot on main, and unintended changes are flagged.

The screenshot rule is mechanical, so no model decides what was intended. References are files in the repository, one per screen and width. A screen whose rendering differs from main's reference by more than the project's threshold counts as intended only when the change updates that reference in the same change, so the diff shows the old and the new look to every reviewer and the Designer scores the new one. Otherwise the check fails and names each screen and width that changed. An updated reference must match the head's own rendering within the same threshold, so a change cannot replace a reference with an image it does not produce.

Every screen and width in the project's page list needs a reference on the head, whatever main holds: a head without one (a reference the change deletes, or one that was never added) fails the check, which names it as a missing reference, even when its rendering matches main, so deleting a reference cannot switch the comparison off. A screen and width without a reference on main (a new screen or a new width) has nothing to compare with, so it never passes by default: the check fails the same way until the change adds one. A first reference is accepted under the same rule as an update: it must match the head's own rendering within the threshold, and the diff shows it to every reviewer and the Designer, who scores it as a new screen. A reference file for a screen or width the page list does not name fails the check too, so a removed screen takes its references with it.

The thresholds and the list of pages are the project's check settings, which live in protected files like every check's configuration, so a change cannot loosen the checks that judge it.

## Layer 2: a rubric with evidence

The Designer scores each screen a change touches against fixed usability heuristics: clarity, feedback, states, consistency, error recovery and copy.

- **Every score cites evidence:** a region of a screenshot and what is wrong there. A score without evidence is an invalid answer.
- **A low score is a review finding, not a gate.** It reaches the change as a finding of the Designer's review and is handled like any other finding ([Reviews](07-reviews.md)); it never fails a check.
- **Scores are kept per screen over time,** so a screen that gets worse shows as a regression on the [dashboard](13-dashboard.md#designer-scores).

## Layer 3: taste by blind pairwise comparison

Some choices are visible but not wrong either way (a layout, an emphasis, a tone of copy). For these the Designer learns the owner's taste instead of guessing it.

- **Variants and a ladder:** for a visible design choice the Designer creates variants and ranks them in pairs into a preference ladder.
- **What a variant is:** the Designer writes no code. It creates a variant as a typed description (the choice, the screen, and what differs, such as a token value, a layout or a line of copy). A build job implements each variant as a commit of its own on top of the head; those commits are kept by the orchestrator, never pushed to the change's branch and never merged. The check environment renders each one with the same tools as Layer 1, and the rendered screenshots are what the Designer ranks and the owner sees. Variant commits pass the same hand-back checks as any build, so a variant that fails them is dropped and shown, never ranked.
- **Variant builds are isolated:** a variant build job gets none of the project's credentials, even when the owner opted in for build jobs, and no external dependency's address on its egress allowlist; a dependency the page needs runs as a bundled sidecar of that job only ([Threat model](14-threat-model.md#external-dependencies)). A render job, for a head or a variant, is isolated the same way, so code written for a variant reaches no shared instance.
- **The owner votes blind** on a sample of those pairs: the owner sees two variants' screenshots, in an order plain code picks at random, without knowing which one the Designer preferred.
- **Agreement is measured and shown:** how often the Designer's ranking matches the owner's votes, shown on the [dashboard](13-dashboard.md#designer-scores).
- **Asking follows agreement.** While agreement is low, choices with character go to the owner as options; as it rises, fewer are asked. With no votes yet, or without the headless browser, agreement is not measured and counts as low.

## Tools

The tools these layers need (axe-core, Lighthouse, a headless browser) are opt-in per project and run in the check environment, never in the Designer's own session, so the simplest install needs none of them ([Reviews](07-reviews.md#the-designer-planned)).

Without a tool, what needs it does not run: it neither blocks nor counts as passed. A Layer 1 check whose tool is not enabled is reported as "not run" with the reason, on the change and on the dashboard; the design-token check reads the code and needs no tool. Without the headless browser there are no screenshots, so the axe-core, layout, Lighthouse and screenshot checks are not run, Layer 2 gives no scores (the Designer's review records "rubric scores unavailable: no browser" for each screen the change touches, never a score or a pass, and the screen's history shows the gap, never a zero), and Layer 3 builds no variants: agreement cannot be measured, so it counts as low and choices with character go to the owner as options.

## Screenshots leave only after a scan

A screenshot shows what the rendered code prints, and that code was written by builders that each hold their subscription credential and, on the owner's opt-in, the project's ([Threat model](14-threat-model.md#residual-risk)). The same holds for a check result, which prints what the checked code prints, and for a reference image a builder commits. A revision can carry code from several build jobs: a head carries every build of its change since main, and a variant renders that head plus the variant's own commit.

### The credential set of a revision

All three scans below (check results, screenshots and committed references) match against the same set, which the orchestrator works out per revision before the scan runs:

- **Recorded builds:** the orchestrator keeps, for each revision it renders, checks or pushes, the build jobs whose commits it contains beyond main (the jobs that built the head, plus the variant build job for a variant). The set holds every credential those jobs held (each one's subscription credential and, on the owner's opt-in, the project's) and every credential of the job that produced the output (a check job holds the project's credentials unless the owner withholds them; a render job holds none).
- **Unrecorded commits:** when the revision contains a commit beyond main that the orchestrator did not record, the set widens: it adds every credential any build job of the project can hold, and keeps every credential of the job that produced the output, for all three scans alike. Widening never drops a credential, so a check job's project credentials that the owner withholds from build jobs stay in the set of its check result.
- **Values unavailable:** when the value of a credential in the set cannot be read (the vault cannot be read, or a credential was removed while the output was produced), the scan cannot be complete, so it counts as a hit: a check result or a screenshot is withheld whole, and the hand-back fails so the commits stay unpublished. A scan never runs against a partial set.

### The three scans

- **Check results** are text: Intakt redacts every value in the set and runs the secret scan before the result reaches the Designer's vendor or the dashboard; a result with a hit is withheld whole and the gap shown on the dashboard ([Threat model](14-threat-model.md#the-designers-session-planned)).
- **Screenshots:** before any screenshot is sent to the Designer's vendor, shown on the dashboard or in the owner's pair view, or committed as a reference, plain code reads it: a process without network or credentials extracts its text and decodes any machine-readable code in it (a QR code or barcode), and the orchestrator matches that text against every value in the set and runs the secret scan over it. A hit, or an image that could not be read within its limits, is withheld whole: the Designer gets Intakt's note that it was withheld, the dashboard shows the gap, and a withheld screen's reference cannot be updated, so its screenshot check fails. The reader is part of the opt-in screenshot tooling, beside the headless browser: the reader is enabled together with the browser, and when the browser is on but the reader is not, every screenshot is withheld.
- **Committed references:** a reference is a file the builder commits, not the scanned render: the threshold lets it differ from the render, and a pushed branch reaches the forge before CI runs. So the hand-back check that runs before Intakt pushes a build's commits ([Threat model](14-threat-model.md#builder)) reads every reference image the commits add or change, the file itself as committed, with the same reader, and matches it against every value in the set of the head it would push, the build that wrote it included, and the secret scan. A hit, or an image that could not be read within its limits, fails the hand-back like any secret-scan hit, so the commits stay unpublished ([Threat model](14-threat-model.md#where-model-output-authorizes-something)); a matching render does not excuse it. The check job reads the references again on the pushed head, as a second barrier.
